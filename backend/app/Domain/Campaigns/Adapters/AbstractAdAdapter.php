<?php

namespace App\Domain\Campaigns\Adapters;

use App\Domain\Actions\ActionPolicy;
use App\Domain\Campaigns\Contracts\AdPlatformAdapter;
use App\Models\AdAccount;
use App\Models\AdConnection;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * What every real adapter shares: credential checks, the capability matrix,
 * and turning one approved action into one method call.
 *
 * An adapter supports an action by defining a method with the action's name
 * (pause, resume, update_budget, duplicate_ad_set, create_campaign, create_ad,
 * update_creative, update_targeting, schedule) and listing it in SUPPORTED. The
 * rest are reported unsupported, with the reason the subclass gives — the
 * product never offers a button the platform will refuse.
 */
abstract class AbstractAdAdapter implements AdPlatformAdapter
{
    /** @var list<string> */
    protected const SUPPORTED = [];

    /** @var array<string, string> action => why it is not supported here */
    protected const UNSUPPORTED_BECAUSE = [];

    public function isImplemented(): bool
    {
        return true;
    }

    public function isConfigured(?AdConnection $connection): bool
    {
        if ($connection === null) {
            return false;
        }

        foreach ($this->requiredCredentials() as $key) {
            if (blank($connection->credential($key))) {
                return false;
            }
        }

        return true;
    }

    public function optionalSettings(): array
    {
        return [];
    }

    public function capabilities(): array
    {
        $out = [];

        foreach (ActionPolicy::ACTIONS as $action) {
            $out[$action] = in_array($action, static::SUPPORTED, true)
                ? ['supported' => true]
                : ['supported' => false, 'reason' => static::UNSUPPORTED_BECAUSE[$action] ?? 'not_built'];
        }

        return $out;
    }

    public function actions(): array
    {
        return static::SUPPORTED;
    }

    public function leadForms(AdConnection $connection, AdAccount $account): ?array
    {
        return null;
    }

    public function execute(AdConnection $connection, string $action, array $target, array $payload): array
    {
        if (! in_array($action, static::SUPPORTED, true)) {
            return $this->refused("{$this->platform()} does not support {$action} here.");
        }

        $method = lcfirst(str_replace('_', '', ucwords($action, '_')));

        try {
            return $this->{$method}($connection, $target, $payload);
        } catch (RuntimeException $e) {
            return $this->refused($e->getMessage());
        }
    }

    /** @return array{ok: false, request: array, response: null, error: string} */
    protected function refused(string $why, array $request = []): array
    {
        return ['ok' => false, 'request' => $request, 'response' => null, 'error' => $why];
    }

    /** The platform's answer, kept for the log; the token is never part of it. */
    protected function answer(Response $response, array $request, ?string $created = null, ?string $error = null): array
    {
        $ok = $response->successful() && $error === null;

        return [
            'ok' => $ok,
            'request' => $request,
            'response' => ['status' => $response->status(), 'body' => $response->json() ?? []],
            'error' => $ok ? null : ($error ?? $this->errorMessage($response)),
            'created_external_id' => $created,
        ];
    }

    protected function errorMessage(Response $response): string
    {
        return (string) ($response->json('error.message') ?? $response->json('message') ?? $response->json('errors.0.message') ?? "HTTP {$response->status()}");
    }

    protected function requireTarget(array $target, string ...$levels): string
    {
        if (! in_array($target['level'] ?? null, $levels, true) || blank($target['external_id'] ?? null)) {
            throw new RuntimeException('This action needs a '.implode(' or ', $levels).' that exists on the platform.');
        }

        return (string) $target['external_id'];
    }

    protected function requireAccount(array $target): string
    {
        if (blank($target['account_external_id'] ?? null)) {
            throw new RuntimeException('The ad account for this change is unknown.');
        }

        return (string) $target['account_external_id'];
    }

    protected static function micros(?float $major): ?int
    {
        return $major === null ? null : (int) round($major * 1_000_000);
    }

    protected static function minorFromMicros(int|string|null $micros): ?int
    {
        return $micros === null || $micros === '' ? null : (int) round(((int) $micros) / 10_000);
    }
}
