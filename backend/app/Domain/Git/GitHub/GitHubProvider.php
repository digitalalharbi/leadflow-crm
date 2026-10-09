<?php

namespace App\Domain\Git\GitHub;

use App\Domain\Git\Contracts\GitProviderInterface;
use App\Domain\Git\DTO\ConnectionCheck;
use App\Domain\Git\DTO\RepositoryDiscovery;
use App\Domain\Git\DTO\RepositorySummary;
use App\Domain\Git\DTO\WebhookState;
use App\Models\Integration;
use App\Models\ProjectRepository;

/**
 * GitHub, answering the four questions مهتم actually asks.
 *
 * Everything about budgets, ETags and backing off lives in the client; this
 * class turns GitHub's shapes into the product's. The split matters because the
 * next provider will have different shapes and the same budget problem.
 */
class GitHubProvider implements GitProviderInterface
{
    public function __construct(private readonly GitHubClient $client) {}

    public function key(): string
    {
        return 'github';
    }

    public function check(Integration $connection): ConnectionCheck
    {
        $result = $this->client->get($connection, '/user');

        if ($result['error'] !== null) {
            return ConnectionCheck::failed($result['error']);
        }

        $user = $result['json'] ?? [];

        return ConnectionCheck::ok(
            account: $user['login'] ?? null,
            scopes: [],
        );
    }

    /**
     * Only what this token may see.
     *
     * Deliberately the authenticated user's repositories rather than an
     * organisation listing: a fine-grained token is granted repository by
     * repository, and asking for an org's whole catalogue would both fail and
     * imply we wanted access we do not need.
     *
     * @return array<int,RepositorySummary>
     */
    public function repositories(Integration $connection, ?string $search = null): array
    {
        $result = $this->client->paginate($connection, '/user/repos', [
            'sort' => 'pushed',
            'affiliation' => 'owner,collaborator,organization_member',
        ], maxPages: 3);

        $rows = collect($result['items'])
            ->map(fn (array $row) => RepositorySummary::fromGitHub($row));

        if (filled($search)) {
            $needle = mb_strtolower(trim((string) $search));
            $rows = $rows->filter(fn (RepositorySummary $r) => str_contains(mb_strtolower($r->fullName), $needle));
        }

        return $rows->values()->all();
    }

    /**
     * The events مهتم asks for, and no more.
     *
     * Asking for `*` would be easier and would also mean every comment, every
     * star and every issue edit arriving at a URL that throws them away — on a
     * busy repository that is thousands of deliveries a day to ignore, and a
     * rate limit for the retries.
     *
     * @var array<int,string>
     */
    private const EVENTS = [
        'push',
        'pull_request',
        'pull_request_review',
        'workflow_run',
        'check_suite',
        'deployment',
        'deployment_status',
        'release',
    ];

    public function ensureWebhook(Integration $connection, ProjectRepository $repository, string $url): WebhookState
    {
        $existing = $this->client->get($connection, "/repos/{$repository->repository_full_name}/hooks");

        if ($existing['error'] === 'forbidden' || $existing['error'] === 'not_found') {
            // A read-only token is a reasonable thing for a careful workspace to
            // issue. It is not an error, it is a different setup path.
            return WebhookState::manual($existing['error']);
        }

        foreach ($existing['json'] ?? [] as $hook) {
            if (($hook['config']['url'] ?? null) === $url) {
                return WebhookState::existing((int) $hook['id']);
            }
        }

        $created = $this->client->post($connection, "/repos/{$repository->repository_full_name}/hooks", [
            'name' => 'web',
            'active' => true,
            'events' => self::EVENTS,
            'config' => [
                'url' => $url,
                'content_type' => 'json',
                'secret' => $repository->webhook_secret,
                'insecure_ssl' => '0',
            ],
        ]);

        if ($created['error'] === 'forbidden') {
            return WebhookState::manual('forbidden');
        }

        if ($created['error'] !== null) {
            return WebhookState::failed($created['error']);
        }

        return WebhookState::created((int) ($created['json']['id'] ?? 0));
    }

    public function removeWebhook(Integration $connection, ProjectRepository $repository): void
    {
        if (! $repository->webhook_id) {
            return;
        }

        // Best effort on purpose: a repository we can no longer reach, or a hook
        // somebody has already deleted, must not stop a project being unlinked.
        $this->client->delete(
            $connection,
            "/repos/{$repository->repository_full_name}/hooks/{$repository->webhook_id}",
        );
    }

    public function discover(Integration $connection, string $fullName): RepositoryDiscovery
    {
        $repo = $this->client->get($connection, "/repos/{$fullName}");

        if ($repo['error'] !== null || ! is_array($repo['json'])) {
            // A repository we cannot read is not a discovery with empty fields;
            // every "none" below would be a lie about the repository rather
            // than a fact about it.
            return new RepositoryDiscovery(
                repository: new RepositorySummary(0, $fullName, 'main', true),
                unavailable: ['repository' => $repo['error'] ?? 'not_found'],
            );
        }

        $summary = RepositorySummary::fromGitHub($repo['json']);
        $unavailable = [];

        $branches = $this->client->paginate($connection, "/repos/{$fullName}/branches", [], maxPages: 2);

        if ($branches['error'] !== null) {
            $unavailable['branches'] = $branches['error'];
        }

        /*
         * Actions can be disabled for a repository, and a token can lack the
         * workflow scope. Both answer with nothing; only one is the user's
         * problem, so the reason is kept rather than flattened to an empty list.
         */
        $workflows = $this->client->get($connection, "/repos/{$fullName}/actions/workflows", ['per_page' => 100]);

        if ($workflows['error'] !== null) {
            $unavailable['workflows'] = $workflows['error'];
        }

        $environments = $this->client->get($connection, "/repos/{$fullName}/environments");

        if ($environments['error'] !== null) {
            $unavailable['environments'] = $environments['error'];
        }

        $release = $this->client->get($connection, "/repos/{$fullName}/releases/latest");

        // A repository that has never been released answers 404, which is a
        // fact about it rather than a failure.
        if ($release['error'] !== null && $release['error'] !== 'not_found') {
            $unavailable['latest_release'] = $release['error'];
        }

        return new RepositoryDiscovery(
            repository: $summary,
            branches: collect($branches['items'])->pluck('name')->filter()->values()->all(),
            workflows: collect($workflows['json']['workflows'] ?? [])
                ->map(fn (array $w) => [
                    'id' => (int) ($w['id'] ?? 0),
                    'name' => (string) ($w['name'] ?? ''),
                    'path' => (string) ($w['path'] ?? ''),
                    'state' => (string) ($w['state'] ?? 'unknown'),
                ])
                ->values()
                ->all(),
            environments: collect($environments['json']['environments'] ?? [])
                ->pluck('name')
                ->filter()
                ->values()
                ->all(),
            latestRelease: $release['json']['tag_name'] ?? null,
            unavailable: $unavailable,
        );
    }
}
