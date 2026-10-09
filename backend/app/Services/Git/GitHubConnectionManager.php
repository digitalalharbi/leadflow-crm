<?php

namespace App\Services\Git;

use App\Domain\Git\Contracts\GitProviderInterface;
use App\Domain\Git\GitHub\GitHubClient;
use App\Domain\Git\GitProviderRegistry;
use App\Enums\IntegrationProvider;
use App\Models\Integration;

/**
 * The workspace's one connection to GitHub.
 *
 * One per tenant, not one per repository: a token is granted to a person or an
 * organisation, and asking somebody to paste the same token once per project is
 * how half the projects end up on a token nobody rotated.
 */
class GitHubConnectionManager
{
    public function __construct(
        private readonly GitProviderRegistry $providers,
        private readonly GitHubClient $client,
    ) {}

    public function provider(): GitProviderInterface
    {
        return $this->providers->get('github');
    }

    /** The row if the workspace has ever connected, null if it never has. */
    public function connection(): ?Integration
    {
        return Integration::where('provider', IntegrationProvider::GitHub->value)->first();
    }

    public function connect(string $token, ?string $account = null): Integration
    {
        $connection = $this->connection() ?? new Integration;

        // Written, never read back. The screen says whether a token is set; the
        // only way to replace one is to paste another.
        $connection->fill([
            'provider' => IntegrationProvider::GitHub->value,
            'name' => 'GitHub',
            'credentials' => ['access_token' => $token],
            'is_active' => true,
            'status' => Integration::CONNECTED,
            'last_error' => null,
            'config' => array_merge($connection->config ?? [], array_filter(['account' => $account])),
        ])->save();

        return $connection->refresh();
    }

    /**
     * Ask GitHub whether the connection still works and record the answer.
     *
     * The recording is the point: the health screen, the sweep and the next
     * webhook all read this row, and none of them should have to spend an API
     * call to find out what the last one already learned.
     */
    public function verify(Integration $connection): array
    {
        $check = $this->provider()->check($connection);

        $connection->forceFill([
            'status' => $check->ok ? Integration::CONNECTED : Integration::ERROR,
            'last_error' => $check->ok ? null : $check->reason,
            'config' => array_merge($connection->config ?? [], array_filter(['account' => $check->account])),
            'last_synced_at' => $check->ok ? now() : $connection->last_synced_at,
        ])->save();

        return $check->toArray();
    }

    /**
     * What the health screen shows without spending a request to find out.
     *
     * `needs_reauth` is kept distinct from a plain error on purpose: a token
     * that was revoked needs a person to paste a new one, and a workspace that
     * has merely run out of hourly budget needs nobody to do anything.
     */
    public function health(?Integration $connection): array
    {
        if (! $connection) {
            return ['state' => 'disconnected', 'account' => null, 'budget' => null, 'last_error' => null];
        }

        $budget = $this->client->budget($connection);

        $state = match (true) {
            $connection->last_error === 'auth_failed' => 'needs_reauth',
            $connection->last_error === 'rate_limited' => 'rate_limited',
            $connection->last_error !== null => 'error',
            $connection->status === Integration::CONNECTED => 'connected',
            default => 'disconnected',
        };

        return [
            'state' => $state,
            'account' => $connection->config['account'] ?? null,
            'budget' => $budget,
            'last_error' => $connection->last_error,
            'last_checked_at' => $connection->last_synced_at?->toIso8601String(),
        ];
    }
}
