<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Services\AuditLogger;
use App\Services\Git\GitHubConnectionManager;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connecting a workspace to its own GitHub, and a project to its repository.
 *
 * The token goes in one direction only: written encrypted, never read back, and
 * the screen shows whether one is set rather than what it is. Replacing one is
 * how you change it.
 */
class GitHubController extends Controller
{
    public function __construct(
        private readonly GitHubConnectionManager $manager,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request)
    {
        $this->authorize('viewAny', Integration::class);

        $connection = $this->manager->connection();

        return ApiResponse::success([
            'connection' => $this->present($connection),
        ]);
    }

    /**
     * Paste a token, and it is checked before it is believed.
     *
     * Storing an unverified credential would leave the health screen saying
     * "connected" about something that has never worked, which is the one state
     * an integration screen must never show.
     */
    public function connect(Request $request)
    {
        $this->authorize('create', Integration::class);

        $data = $request->validate([
            /*
             * Both token shapes GitHub issues: `ghp_` classic and `github_pat_`
             * fine-grained. Checked for shape only — whether it works is a
             * question for GitHub, asked below, and a regex that tried to be
             * authoritative would reject the next prefix they invent.
             */
            'token' => ['required', 'string', 'min:20', 'max:255'],
        ]);

        $connection = $this->manager->connect(trim($data['token']));
        $check = $this->manager->verify($connection);

        if (! $check['ok']) {
            // Kept rather than deleted: the row carries the reason, and the
            // screen can say "that token was refused" instead of silently
            // returning to a disconnected state the user cannot learn from.
            $this->audit->log('github.connect_failed', $request->user(), null, ['reason' => $check['reason']]);

            throw ValidationException::withMessages([
                'token' => [$this->reasonFor($check['reason'])],
            ]);
        }

        $this->audit->log('github.connected', $request->user(), null, ['account' => $check['account']]);

        return ApiResponse::success(['connection' => $this->present($connection->refresh())]);
    }

    public function test(Request $request)
    {
        $this->authorize('viewAny', Integration::class);

        $connection = $this->manager->connection();

        if (! $connection) {
            return ApiResponse::error('GitHub is not connected.', null, 422);
        }

        return ApiResponse::success([
            'check' => $this->manager->verify($connection),
            'connection' => $this->present($connection->refresh()),
        ]);
    }

    public function disconnect(Request $request)
    {
        $connection = $this->manager->connection();

        if (! $connection) {
            return ApiResponse::success(null, 'GitHub was not connected.');
        }

        $this->authorize('delete', $connection);

        /*
         * Mappings are kept and the credential is dropped.
         *
         * Disconnecting is usually "this token was rotated", not "forget which
         * repository every project is built in" — and the second is unrecoverable
         * from the first's screen. A project whose connection has no token shows
         * as needing reconnection, which is what happened.
         */
        $connection->forceFill([
            'credentials' => [],
            'is_active' => false,
            'status' => Integration::NOT_CONNECTED,
            'last_error' => null,
        ])->save();

        $this->audit->log('github.disconnected', $request->user(), null);

        return ApiResponse::success(['connection' => $this->present($connection->refresh())]);
    }

    /** The repositories this token may actually see. */
    public function repositories(Request $request)
    {
        $this->authorize('viewAny', Integration::class);

        $connection = $this->requireConnection();

        $repositories = $this->manager->provider()->repositories($connection, $request->string('search')->toString());

        return ApiResponse::success([
            'repositories' => array_map(fn ($r) => $r->toArray(), $repositories),
        ]);
    }

    /** Everything the mapping screen needs, so nobody types a branch name. */
    public function discover(Request $request)
    {
        $this->authorize('viewAny', Integration::class);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255', 'regex:#^[\w.-]+/[\w.-]+$#'],
        ]);

        $connection = $this->requireConnection();

        return ApiResponse::success([
            'discovery' => $this->manager->provider()->discover($connection, $data['full_name'])->toArray(),
        ]);
    }

    /** Point a project at a repository. */
    public function link(Request $request, string $uuid)
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $project);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255', 'regex:#^[\w.-]+/[\w.-]+$#'],
            'develop_branch' => ['sometimes', 'nullable', 'string', 'max:255'],
            'production_branch' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $connection = $this->requireConnection();
        $discovery = $this->manager->provider()->discover($connection, $data['full_name']);

        if (isset($discovery->unavailable['repository'])) {
            throw ValidationException::withMessages([
                'full_name' => [$this->reasonFor($discovery->unavailable['repository'])],
            ]);
        }

        $suggested = $discovery->suggestedMapping();

        $mapping = ProjectRepository::updateOrCreate(
            ['project_id' => $project->id],
            [
                'integration_id' => $connection->id,
                'provider' => 'github',
                'repository_id' => $discovery->repository->id,
                'repository_full_name' => $discovery->repository->fullName,
                'default_branch' => $discovery->repository->defaultBranch,
                // The user's answer wins; the discovery is only what we would
                // have guessed, and a workspace whose staging is `qa` should not
                // have to argue with us about it.
                'develop_branch' => $data['develop_branch'] ?? $suggested['develop_branch'],
                'production_branch' => $data['production_branch'] ?? $suggested['production_branch'],
                'discovery' => $discovery->toArray(),
                'last_sync_at' => now(),
                'last_error' => null,
            ],
        );

        /*
         * The webhook is set up here rather than offered as an instruction.
         * An integration whose first step is "now paste this into GitHub" is one
         * most workspaces never finish — and a repository that was linked but
         * never wired up looks connected while telling us nothing.
         */
        $this->ensureWebhook($connection, $mapping);

        $this->audit->log('github.repository_linked', $request->user(), $project, [
            'repository' => $mapping->repository_full_name,
            'webhook' => $mapping->webhook_status,
        ]);

        return ApiResponse::success(['repository' => $this->presentMapping($mapping)]);
    }

    public function unlink(Request $request, string $uuid)
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $project);

        $mapping = ProjectRepository::where('project_id', $project->id)->first();

        if ($mapping) {
            // Best effort, and before the row goes: a hook left behind would
            // keep posting to a key that no longer resolves, and GitHub would
            // eventually disable it with an email to somebody who has no idea
            // what it is.
            $this->manager->provider()->removeWebhook($this->manager->connection() ?? new Integration, $mapping);

            $mapping->delete();
            $this->audit->log('github.repository_unlinked', $request->user(), $project, [
                'repository' => $mapping->repository_full_name,
            ]);
        }

        return ApiResponse::success(null, 'Repository unlinked.');
    }

    /** What a project's Development tab knows before it asks GitHub anything. */
    public function forProject(Request $request, string $uuid)
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $project);

        $mapping = ProjectRepository::where('project_id', $project->id)->first();

        return ApiResponse::success([
            'repository' => $mapping ? $this->presentMapping($mapping) : null,
            'connection' => $this->present($this->manager->connection()),
        ]);
    }

    /**
     * Give this repository a key and a secret of its own, then ask GitHub to
     * use them.
     *
     * One secret per repository, not per workspace: a secret shared across
     * repositories is one that cannot be rotated for a single project, and the
     * usual reason to rotate is that one of them leaked.
     */
    private function ensureWebhook(Integration $connection, ProjectRepository $mapping): void
    {
        if (blank($mapping->webhook_key)) {
            $mapping->forceFill([
                'webhook_key' => (string) Str::uuid(),
                'webhook_secret' => Str::random(48),
            ])->save();
        }

        $state = $this->manager->provider()->ensureWebhook($connection, $mapping->refresh(), $mapping->webhookUrl());

        $mapping->forceFill([
            'webhook_id' => $state->id,
            'webhook_status' => $state->status === 'ok' ? 'ok' : ($state->status === 'manual' ? 'manual' : 'missing'),
        ])->save();
    }

    private function requireConnection(): Integration
    {
        $connection = $this->manager->connection();

        if (! $connection || blank($connection->credentials['access_token'] ?? null)) {
            throw ValidationException::withMessages([
                'connection' => ['GitHub is not connected for this workspace.'],
            ]);
        }

        return $connection;
    }

    /** A reason key the interface translates, never GitHub's own wording. */
    private function reasonFor(?string $reason): string
    {
        return match ($reason) {
            'auth_failed' => 'github.auth_failed',
            'rate_limited' => 'github.rate_limited',
            'forbidden' => 'github.forbidden',
            'not_found' => 'github.not_found',
            'unreachable' => 'github.unreachable',
            default => 'github.request_failed',
        };
    }

    private function present(?Integration $connection): array
    {
        $health = $this->manager->health($connection);

        return $health + [
            'connected' => $connection !== null && filled($connection->credentials['access_token'] ?? null),
            // Never the token. Only that there is one.
            'has_token' => $connection !== null && filled($connection->credentials['access_token'] ?? null),
        ];
    }

    private function presentMapping(ProjectRepository $mapping): array
    {
        return [
            'id' => $mapping->uuid,
            'provider' => $mapping->provider,
            'full_name' => $mapping->repository_full_name,
            'url' => $mapping->webUrl(),
            'default_branch' => $mapping->default_branch,
            'develop_branch' => $mapping->develop_branch,
            'production_branch' => $mapping->production_branch,
            'last_sync_at' => $mapping->last_sync_at?->toIso8601String(),
            'last_webhook_at' => $mapping->last_webhook_at?->toIso8601String(),
            'discovery' => $mapping->discovery,
            'readiness' => $mapping->readiness(),
            'webhook' => [
                'status' => $mapping->webhook_status,
                // Shown so a workspace whose token may not manage hooks can add
                // one by hand. The secret is not: it is written into GitHub's
                // own form by us, or regenerated if somebody needs it again.
                'url' => $mapping->webhookUrl(),
                'signature_failures' => $mapping->signature_failures,
            ],
        ];
    }
}
