<?php

namespace App\Domain\Git\GitHub;

use App\Models\Integration;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Every call مهتم makes to GitHub goes through here.
 *
 * Three things it exists to get right, none of which belong in a provider that
 * is trying to answer a product question:
 *
 *  - **The budget.** GitHub gives 5,000 requests an hour per token and stops
 *    answering when they are gone — for everything, not just the call that
 *    spent them. So the remaining count is read off every response and kept
 *    where the health screen can see it without spending one to ask.
 *
 *  - **Not asking twice.** A repository that has not changed answers 304 to a
 *    request carrying its ETag, and a 304 costs nothing against the budget.
 *    Every GET here is conditional, which is what makes a reconciliation sweep
 *    every ten minutes affordable.
 *
 *  - **Backing off.** A secondary rate limit is GitHub saying "you are being
 *    rude", and the only correct reply is to wait the time it names. Retrying
 *    immediately is how a token gets suspended.
 *
 * It never throws for a failed request. A broken connection is a state the
 * product shows, not an exception something has to catch.
 */
class GitHubClient
{
    public const BUDGET_LOW = 200;

    public function __construct(private readonly string $baseUrl)
    {
        //
    }

    /**
     * A conditional GET. Returns null when the resource is unchanged, so a
     * caller can keep what it already stored.
     *
     * @param  array<string,mixed>  $query
     * @return array{status:int,json:array|null,etag:?string,unchanged:bool,error:?string}
     */
    public function get(Integration $connection, string $path, array $query = []): array
    {
        $cacheKey = $this->etagKey($connection, $path, $query);
        $etag = Cache::get($cacheKey);

        $response = $this->send($connection, 'get', $path, $query, $etag);

        if ($response === null) {
            return ['status' => 0, 'json' => null, 'etag' => null, 'unchanged' => false, 'error' => 'unreachable'];
        }

        $this->recordBudget($connection, $response);

        if ($response->status() === 304) {
            return ['status' => 304, 'json' => null, 'etag' => $etag, 'unchanged' => true, 'error' => null];
        }

        if ($response->successful()) {
            $fresh = $response->header('ETag');

            if ($fresh !== '') {
                // An hour is longer than any reconciliation interval, and a
                // stale ETag costs one ordinary request to discover.
                Cache::put($cacheKey, $fresh, now()->addHour());
            }

            return [
                'status' => $response->status(),
                'json' => $response->json(),
                'etag' => $fresh ?: null,
                'unchanged' => false,
                'error' => null,
            ];
        }

        return [
            'status' => $response->status(),
            'json' => null,
            'etag' => null,
            'unchanged' => false,
            'error' => $this->classify($response),
        ];
    }

    /**
     * Follows `Link: rel="next"` until the pages run out or the cap is reached.
     *
     * Capped on purpose. A repository with nine thousand pull requests is not a
     * reason to spend an hour's budget on one screen; the sweep will catch up
     * with the rest, and the cap is what stops a first connection from
     * exhausting the token before anybody has seen a page.
     *
     * @return array{items:array,error:?string,truncated:bool}
     */
    public function paginate(Integration $connection, string $path, array $query = [], int $maxPages = 5): array
    {
        $items = [];
        $page = 1;

        while ($page <= $maxPages) {
            $result = $this->get($connection, $path, $query + ['per_page' => 100, 'page' => $page]);

            if ($result['error'] !== null) {
                return ['items' => $items, 'error' => $result['error'], 'truncated' => true];
            }

            // Unchanged on the first page means nothing has moved at all; the
            // caller keeps what it had rather than storing an empty list.
            if ($result['unchanged']) {
                return ['items' => $items, 'error' => null, 'truncated' => false];
            }

            $batch = $result['json'] ?? [];

            if ($batch === []) {
                break;
            }

            $items = array_merge($items, $batch);

            if (count($batch) < 100) {
                break;
            }

            $page++;
        }

        return ['items' => $items, 'error' => null, 'truncated' => $page > $maxPages];
    }

    /**
     * A write. Never conditional and never cached: the point of a POST is that
     * something changes, and an ETag would be answering a question nobody asked.
     *
     * @return array{status:int,json:array|null,error:?string}
     */
    public function post(Integration $connection, string $path, array $body, string $method = 'post'): array
    {
        $response = $this->sendJson($connection, $method, $path, $body);

        if ($response === null) {
            return ['status' => 0, 'json' => null, 'error' => 'unreachable'];
        }

        $this->recordBudget($connection, $response);

        if ($response->successful()) {
            return ['status' => $response->status(), 'json' => $response->json(), 'error' => null];
        }

        return ['status' => $response->status(), 'json' => null, 'error' => $this->classify($response)];
    }

    /** As `post`, for a request that carries no body. */
    public function delete(Integration $connection, string $path): array
    {
        return $this->post($connection, $path, [], 'delete');
    }

    private function sendJson(Integration $connection, string $method, string $path, array $body): ?Response
    {
        $token = $connection->credentials['access_token'] ?? null;

        if (! $token) {
            return null;
        }

        try {
            return Http::withToken($token)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->timeout((int) config('services.github.timeout', 15))
                ->retry(2, 500, throw: false)
                ->{$method}(rtrim($this->baseUrl, '/').'/'.ltrim($path, '/'), $body);
        } catch (\Throwable $e) {
            Log::warning('github.transport_failed', [
                'integration' => $connection->uuid,
                'path' => $path,
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    /** What is left of this connection's hourly budget, for the health screen. */
    public function budget(Integration $connection): array
    {
        return Cache::get($this->budgetKey($connection)) ?? [
            'remaining' => null,
            'limit' => null,
            'resets_at' => null,
            'low' => false,
        ];
    }

    /**
     * Whether this connection should be left alone for now.
     *
     * Read before a sweep rather than discovered during one: a reconciliation
     * that starts with nothing left in the budget spends its whole run
     * collecting 403s, and the next person to open a screen pays for it.
     */
    public function shouldHoldOff(Integration $connection): bool
    {
        $budget = $this->budget($connection);

        if ($budget['remaining'] === null) {
            return false;
        }

        $resets = $budget['resets_at'] ? strtotime((string) $budget['resets_at']) : null;

        return $budget['remaining'] <= 0 && $resets !== null && $resets > time();
    }

    private function send(Integration $connection, string $method, string $path, array $query, ?string $etag): ?Response
    {
        $token = $connection->credentials['access_token'] ?? null;

        if (! $token) {
            return null;
        }

        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];

        if ($etag) {
            $headers['If-None-Match'] = $etag;
        }

        try {
            return Http::withToken($token)
                ->withHeaders($headers)
                ->timeout((int) config('services.github.timeout', 15))
                /*
                 * `throw: false` keeps this to connection failures — a dropped
                 * socket is worth a second attempt. An HTTP status is not:
                 * retrying a 403 is how a token earns a secondary rate limit,
                 * and retrying the 304 we were hoping for spends budget to be
                 * told the same thing twice.
                 */
                ->retry(2, 500, throw: false)
                ->{$method}(rtrim($this->baseUrl, '/').'/'.ltrim($path, '/'), $query);
        } catch (\Throwable $e) {
            // Never the message: a transport exception can carry the URL, and
            // the URL carries the repository name of a private repository.
            Log::warning('github.transport_failed', [
                'integration' => $connection->uuid,
                'path' => $path,
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    /**
     * Why a request failed, in terms the product can act on.
     *
     * A 403 is two completely different problems wearing the same status: the
     * token may lack a scope, or the hour's budget may be gone. Telling a user
     * to reconnect when they simply need to wait is the worse of the two
     * mistakes, so the headers decide.
     */
    private function classify(Response $response): string
    {
        $status = $response->status();

        if ($status === 401) {
            return 'auth_failed';
        }

        if ($status === 403 || $status === 429) {
            $remaining = $response->header('x-ratelimit-remaining');

            if ($remaining !== '' && (int) $remaining <= 0) {
                return 'rate_limited';
            }

            if ($response->header('retry-after') !== '') {
                return 'rate_limited';
            }

            return 'forbidden';
        }

        if ($status === 404) {
            // Indistinguishable from "it does not exist" by design on GitHub's
            // side: a token that cannot see a private repository is told the
            // same thing a stranger is. Either way the mapping is wrong.
            return 'not_found';
        }

        return $status >= 500 ? 'unreachable' : 'request_failed';
    }

    private function recordBudget(Integration $connection, Response $response): void
    {
        $remaining = $response->header('x-ratelimit-remaining');

        if ($remaining === '') {
            return;
        }

        $reset = $response->header('x-ratelimit-reset');
        $resetsAt = $reset !== '' ? now()->setTimestamp((int) $reset)->toIso8601String() : null;

        Cache::put($this->budgetKey($connection), [
            'remaining' => (int) $remaining,
            'limit' => $response->header('x-ratelimit-limit') !== '' ? (int) $response->header('x-ratelimit-limit') : null,
            'resets_at' => $resetsAt,
            'low' => (int) $remaining <= self::BUDGET_LOW,
        ], now()->addHours(2));
    }

    private function budgetKey(Integration $connection): string
    {
        return "github:budget:{$connection->tenant_id}:{$connection->id}";
    }

    private function etagKey(Integration $connection, string $path, array $query): string
    {
        ksort($query);

        return 'github:etag:'.$connection->id.':'.sha1($path.'?'.http_build_query($query));
    }
}
