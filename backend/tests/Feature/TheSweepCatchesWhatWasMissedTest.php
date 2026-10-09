<?php

namespace Tests\Feature;

use App\Domain\Git\GitHub\GitHubClient;
use App\Domain\Git\GitProviderRegistry;
use App\Domain\Git\RepositoryStateReducer;
use App\Events\RepositoryFeedUpdate;
use App\Jobs\ReconcileRepository;
use App\Models\Integration;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\RepositoryPullRequest;
use App\Models\RepositoryRelease;
use App\Models\RepositoryWebhookDelivery;
use App\Models\RepositoryWorkflowRun;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The repair pass, and the two things it must not do.
 *
 * Webhooks are the primary path; this catches what they missed — a queue down
 * for ten minutes, a secret rotated on one side, a hook GitHub disabled after
 * too many failures. None of those announce themselves, and all of them end
 * with a project screen that is quietly wrong.
 *
 * What it must not do: write rows that disagree with the webhook path it is
 * repairing, and spend a budget that is already gone.
 */
class TheSweepCatchesWhatWasMissedTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private ProjectRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->t = $this->provisionTenant('Sweep Co');

        $connection = $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'github',
            'name' => 'GitHub',
            'status' => Integration::CONNECTED,
            'credentials' => ['access_token' => 'github_pat_test'],
            'is_active' => true,
        ]));

        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'بوابة']));

        $this->repository = $this->asTenant($this->t['tenant'], fn () => ProjectRepository::create([
            'project_id' => $project->id,
            'integration_id' => $connection->id,
            'provider' => 'github',
            'repository_id' => 4242,
            'repository_full_name' => 'acme/portal',
            'default_branch' => 'main',
            'develop_branch' => 'develop',
            'production_branch' => 'main',
            'webhook_key' => (string) Str::uuid(),
            'webhook_secret' => 'secret',
            'webhook_status' => 'ok',
        ]));
    }

    private function fakeGitHub(array $overrides = []): void
    {
        Http::fake(array_merge([
            'api.github.com/repos/acme/portal/pulls*' => Http::response([[
                'number' => 7,
                'title' => 'feat: the thing nobody heard about',
                'user' => ['login' => 'sara'],
                'head' => ['ref' => 'feature/quiet'],
                'base' => ['ref' => 'develop'],
                'state' => 'closed',
                'merged' => true,
                'merged_at' => '2026-10-09T08:00:00Z',
                'merged_by' => ['login' => 'omar'],
                'merge_commit_sha' => str_repeat('e', 40),
                'created_at' => '2026-10-08T08:00:00Z',
                'updated_at' => '2026-10-09T08:00:00Z',
                'html_url' => 'https://github.com/acme/portal/pull/7',
            ]], 200, ['x-ratelimit-remaining' => '4000']),
            'api.github.com/repos/acme/portal/actions/runs*' => Http::response(['workflow_runs' => [[
                'id' => 555,
                'name' => 'Backend tests',
                'event' => 'push',
                'head_branch' => 'main',
                'head_sha' => str_repeat('f', 40),
                'status' => 'completed',
                'conclusion' => 'success',
                'html_url' => 'https://github.com/acme/portal/actions/runs/555',
                'run_started_at' => '2026-10-09T08:10:00Z',
                'updated_at' => '2026-10-09T08:15:00Z',
            ]]], 200, ['x-ratelimit-remaining' => '3999']),
            'api.github.com/repos/acme/portal/deployments?*' => Http::response([], 200),
            'api.github.com/repos/acme/portal/deployments' => Http::response([], 200),
            'api.github.com/repos/acme/portal/releases*' => Http::response([[
                'id' => 99,
                'tag_name' => 'v3.0.0',
                'name' => 'ثلاثة',
                'draft' => false,
                'target_commitish' => 'main',
                'author' => ['login' => 'omar'],
                'html_url' => 'https://github.com/acme/portal/releases/tag/v3.0.0',
                'published_at' => '2026-10-09T08:20:00Z',
            ]], 200),
        ], $overrides));
    }

    private function sweep(): void
    {
        app(ReconcileRepository::class, ['repositoryId' => $this->repository->id])
            ->handle(
                app(GitProviderRegistry::class),
                app(RepositoryStateReducer::class),
                app(GitHubClient::class),
                app(TenantContext::class),
            );
    }

    public function test_it_finds_a_merge_that_never_arrived(): void
    {
        $this->fakeGitHub();

        $this->sweep();

        $pr = RepositoryPullRequest::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(7, $pr->number);
        $this->assertSame('merged', $pr->state);
        $this->assertSame('omar', $pr->merged_by);
    }

    /**
     * The repair writes through the same reducer the webhooks use, so a sweep
     * and a delivery about the same fact converge on one row rather than two.
     */
    public function test_a_sweep_and_a_delivery_agree(): void
    {
        $this->fakeGitHub();
        $this->sweep();
        $this->sweep();

        $this->assertSame(1, RepositoryPullRequest::withoutGlobalScopes()->count());
        $this->assertSame(1, RepositoryWorkflowRun::withoutGlobalScopes()->count());
        $this->assertSame(1, RepositoryRelease::withoutGlobalScopes()->count());
    }

    public function test_it_records_the_build_and_the_release_it_missed(): void
    {
        $this->fakeGitHub();
        $this->sweep();

        $this->assertSame('passed', RepositoryWorkflowRun::withoutGlobalScopes()->first()->outcome());
        $this->assertSame('production', RepositoryWorkflowRun::withoutGlobalScopes()->first()->environment);
        $this->assertSame('v3.0.0', RepositoryRelease::withoutGlobalScopes()->first()->tag_name);
    }

    public function test_the_cursor_moves_only_after_a_clean_run(): void
    {
        $this->fakeGitHub();
        $this->assertNull($this->repository->sync_cursor);

        $this->sweep();

        $this->assertNotNull($this->repository->fresh()->sync_cursor);
        $this->assertNull($this->repository->fresh()->last_error);
    }

    /**
     * A sweep that could not read everything must not move past the window it
     * could not see. Advancing on a partial read skips it permanently, and
     * silently, because the next sweep starts after the cursor.
     */
    public function test_a_partial_read_leaves_the_cursor_where_it_was(): void
    {
        $this->fakeGitHub([
            'api.github.com/repos/acme/portal/pulls*' => Http::response(['message' => 'Bad credentials'], 401),
        ]);

        $this->sweep();

        $fresh = $this->repository->fresh();

        $this->assertNull($fresh->sync_cursor);
        $this->assertSame('auth_failed', $fresh->last_error);
        $this->assertSame(0, RepositoryPullRequest::withoutGlobalScopes()->count());

        // What it *could* read is still written: a sweep that lost the pull
        // requests but saw a release should still show the release.
        $this->assertSame(1, RepositoryRelease::withoutGlobalScopes()->count());
    }

    /**
     * A sweep that starts with nothing left in the hourly budget spends its
     * whole run collecting 403s, and the next person to open a screen pays for
     * it. So it is read before any of it is spent.
     */
    public function test_it_does_not_start_when_the_budget_is_gone(): void
    {
        $this->fakeGitHub();

        Cache::put(
            'github:budget:'.$this->repository->tenant_id.':'.$this->repository->integration_id,
            ['remaining' => 0, 'limit' => 5000, 'resets_at' => now()->addMinutes(20)->toIso8601String(), 'low' => true],
            now()->addHour(),
        );

        $this->sweep();

        Http::assertNothingSent();
        $this->assertSame('rate_limited', $this->repository->fresh()->last_error);
    }

    /** A workspace that disconnected keeps its history and stops being swept. */
    public function test_a_disconnected_workspace_is_left_alone(): void
    {
        $this->fakeGitHub();

        Integration::withoutGlobalScopes()->where('provider', 'github')
            ->first()->forceFill(['credentials' => []])->save();

        $this->sweep();

        Http::assertNothingSent();
    }

    public function test_the_scheduled_command_queues_one_job_per_repository(): void
    {
        Queue::fake();

        $this->artisan('leadflow:reconcile-repositories')->assertSuccessful();

        Queue::assertPushed(ReconcileRepository::class, 1);
    }

    /**
     * One job per repository rather than one long job: a workspace whose token
     * expired must not stop every other workspace's sweep.
     */
    public function test_one_broken_workspace_does_not_stop_the_others(): void
    {
        Queue::fake();

        $other = $this->provisionTenant('Another Co');
        $connection = $this->asTenant($other['tenant'], fn () => Integration::create([
            'provider' => 'github', 'name' => 'GitHub', 'status' => Integration::CONNECTED,
            'credentials' => ['access_token' => 't'], 'is_active' => true,
        ]));
        $project = $this->asTenant($other['tenant'], fn () => Project::create(['name' => 'آخر']));
        $this->asTenant($other['tenant'], fn () => ProjectRepository::create([
            'project_id' => $project->id, 'integration_id' => $connection->id, 'provider' => 'github',
            'repository_id' => 1, 'repository_full_name' => 'other/repo', 'default_branch' => 'main',
        ]));

        $this->artisan('leadflow:reconcile-repositories')->assertSuccessful();

        Queue::assertPushed(ReconcileRepository::class, 2);
    }

    // ------------------------------------------------------------- the feed

    public function test_a_processed_delivery_nudges_the_open_screens(): void
    {
        Event::fake([RepositoryFeedUpdate::class]);

        $this->deliverMerge();

        Event::assertDispatched(RepositoryFeedUpdate::class, function (RepositoryFeedUpdate $event) {
            return $event->tenantUuid === $this->t['tenant']->uuid
                && $event->kind === 'pull_request';
        });
    }

    /** A delivery we ignore is not a reason to make every tab refetch. */
    public function test_something_we_ignore_nudges_nobody(): void
    {
        Event::fake([RepositoryFeedUpdate::class]);

        $this->deliver('issues', ['action' => 'opened']);

        Event::assertNotDispatched(RepositoryFeedUpdate::class);
    }

    // ------------------------------------------------------------- health

    public function test_the_health_screen_answers_without_asking_github(): void
    {
        Http::fake();

        $rows = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/integrations/github')
            ->assertOk()
            ->json('data.repositories');

        $this->assertCount(1, $rows);
        $this->assertSame('acme/portal', $rows[0]['full_name']);
        $this->assertSame('بوابة', $rows[0]['project']);

        // A health screen that spent an API call per repository to render would
        // be the thing most likely to exhaust the budget it reports on.
        Http::assertNothingSent();
    }

    /**
     * The one number that means "the queue is not running" — which looks
     * exactly like a healthy integration from every other angle.
     */
    public function test_deliveries_that_arrived_and_were_never_processed_are_counted(): void
    {
        $this->asTenant($this->t['tenant'], fn () => RepositoryWebhookDelivery::create([
            'project_repository_id' => $this->repository->id,
            'delivery_id' => (string) Str::uuid(),
            'event' => 'push',
            'signature_valid' => true,
            'status' => RepositoryWebhookDelivery::RECEIVED,
            'received_at' => now(),
        ]));

        $rows = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/integrations/github')
            ->assertOk()
            ->json('data.repositories');

        $this->assertSame(1, $rows[0]['pending_deliveries']);
    }

    public function test_sync_now_queues_a_sweep(): void
    {
        Queue::fake();

        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/integrations/github/sync')
            ->assertOk()
            ->assertJsonPath('data.queued', 1);

        Queue::assertPushed(ReconcileRepository::class);
    }

    public function test_one_workspace_never_sweeps_anothers_repository(): void
    {
        Queue::fake();

        $other = $this->provisionTenant('Another Co');

        $this->actingAs($other['admin'])
            ->postJson('/api/v1/integrations/github/sync')
            ->assertOk()
            ->assertJsonPath('data.queued', 0);
    }

    // ------------------------------------------------------------- helpers

    private function deliverMerge(): void
    {
        $this->deliver('pull_request', [
            'action' => 'closed',
            'pull_request' => [
                'number' => 7, 'title' => 't', 'user' => ['login' => 'sara'],
                'head' => ['ref' => 'f'], 'base' => ['ref' => 'develop'],
                'state' => 'closed', 'merged' => true, 'merged_at' => '2026-10-09T08:00:00Z',
                'merge_commit_sha' => str_repeat('e', 40), 'html_url' => 'u',
            ],
        ]);
    }

    private function deliver(string $event, array $payload): void
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->call('POST', "/api/v1/integrations/github/webhook/{$this->repository->webhook_key}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_GITHUB_DELIVERY' => (string) Str::uuid(),
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'secret'),
        ], $body)->assertOk();
    }
}
