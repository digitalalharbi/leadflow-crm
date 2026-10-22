<?php

namespace Tests\Feature;

use App\Jobs\ProcessRepositoryDelivery;
use App\Models\Integration;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\RepositoryActivity;
use App\Models\RepositoryDeployment;
use App\Models\RepositoryPullRequest;
use App\Models\RepositoryRelease;
use App\Models\RepositoryWebhookDelivery;
use App\Models\RepositoryWorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * What GitHub tells مهتم, and what مهتم does with it.
 *
 * The public endpoint is the only door into a workspace's development history,
 * so most of this is about refusing: an unsigned body, a signature computed
 * over a re-encoded payload, a delivery that has already been seen, a key that
 * does not exist. The rest is the two distinctions the product turns on — a
 * skipped run is not a passing one, and a deployment somebody reported is not
 * a deployment anybody verified.
 */
class GitHubTellsUsWhatHappenedTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private ProjectRepository $repository;

    private string $secret = 'a-shared-secret-value-for-signing';

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Delivery Co');

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
            'webhook_secret' => $this->secret,
            'webhook_status' => 'ok',
        ]));
    }

    private function deliver(string $event, array $payload, array $options = []): TestResponse
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $secret = $options['secret'] ?? $this->secret;
        $signature = $options['signature'] ?? 'sha256='.hash_hmac('sha256', $body, $secret);
        $key = $options['key'] ?? $this->repository->webhook_key;

        return $this->call(
            'POST',
            "/api/v1/integrations/github/webhook/{$key}",
            [],
            [],
            [],
            array_filter([
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_GITHUB_EVENT' => $event,
                'HTTP_X_GITHUB_DELIVERY' => $options['delivery'] ?? (string) Str::uuid(),
                'HTTP_X_HUB_SIGNATURE_256' => $options['omit_signature'] ?? false ? null : $signature,
            ]),
            $body,
        );
    }

    private function state(string $model): object
    {
        return $model::withoutGlobalScopes()->firstOrFail();
    }

    // ------------------------------------------------------------- the door

    public function test_a_signed_delivery_is_accepted(): void
    {
        $this->deliver('ping', ['zen' => 'Anything added dilutes everything else.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');
    }

    public function test_an_unsigned_delivery_is_refused(): void
    {
        $this->deliver('push', ['ref' => 'refs/heads/main'], ['omit_signature' => true])
            ->assertStatus(401);

        $this->assertSame(0, RepositoryPullRequest::withoutGlobalScopes()->count());
    }

    /**
     * The one that lets anybody write into a customer's history if it is wrong.
     */
    public function test_a_delivery_signed_with_the_wrong_secret_is_refused(): void
    {
        $this->deliver('push', ['ref' => 'refs/heads/main'], ['secret' => 'not-the-secret'])
            ->assertStatus(401);
    }

    /**
     * The signature covers the raw body. Verifying a re-encoded array would
     * reorder keys and change escaping, and the hash would stop matching for
     * reasons that look random — the usual "fix" for which is to stop checking.
     */
    public function test_the_signature_is_checked_against_the_body_that_was_sent(): void
    {
        $payload = ['zen' => 'مرحبا', 'b' => 1, 'a' => 2];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->call(
            'POST',
            "/api/v1/integrations/github/webhook/{$this->repository->webhook_key}",
            [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_GITHUB_EVENT' => 'ping',
                'HTTP_X_GITHUB_DELIVERY' => (string) Str::uuid(),
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $this->secret),
            ],
            $body,
        )->assertOk();
    }

    public function test_a_key_that_does_not_exist_says_nothing_about_keys_that_do(): void
    {
        $unknown = $this->deliver('ping', [], ['key' => (string) Str::uuid()]);
        $wrong = $this->deliver('ping', [], ['secret' => 'nope']);

        $unknown->assertStatus(401);
        $wrong->assertStatus(401);
        $this->assertSame($unknown->json('message'), $wrong->json('message'));
    }

    /** Counted, so the health screen can tell a rotated secret from an attack. */
    public function test_a_failed_signature_is_counted_and_recorded(): void
    {
        $this->deliver('push', ['ref' => 'refs/heads/main'], ['secret' => 'nope'])->assertStatus(401);

        $this->assertSame(1, $this->repository->fresh()->signature_failures);
        $this->assertSame(
            RepositoryWebhookDelivery::REJECTED,
            RepositoryWebhookDelivery::withoutGlobalScopes()->first()?->status,
        );
    }

    /**
     * GitHub retries a delivery it believes failed. Replaying it would merge
     * the same pull request twice onto the timeline.
     */
    public function test_the_same_delivery_twice_is_recognised_rather_than_replayed(): void
    {
        $id = (string) Str::uuid();
        $payload = $this->mergedPullRequest();

        $this->deliver('pull_request', $payload, ['delivery' => $id])->assertJsonPath('data.status', 'accepted');
        $this->deliver('pull_request', $payload, ['delivery' => $id])->assertJsonPath('data.status', 'duplicate');

        $this->assertSame(1, RepositoryWebhookDelivery::withoutGlobalScopes()->count());
        $this->assertSame(1, RepositoryActivity::withoutGlobalScopes()->where('kind', 'pr_merged')->count());
    }

    /** GitHub waits ten seconds and retries; the reducer does not run in that. */
    public function test_the_work_happens_on_a_queue(): void
    {
        Queue::fake();

        $this->deliver('pull_request', $this->mergedPullRequest())->assertOk();

        Queue::assertPushed(ProcessRepositoryDelivery::class);
    }

    /**
     * A `push` body carries every commit message and every author's email
     * address. None of it is needed once the reducer has run, and all of it
     * would sit in this table forever.
     */
    public function test_the_payload_is_not_kept(): void
    {
        $this->deliver('push', [
            'ref' => 'refs/heads/main',
            'commits' => [[
                'id' => str_repeat('a', 40),
                'message' => 'fix: a thing',
                'url' => 'https://github.com/acme/portal/commit/aaa',
                'timestamp' => '2026-10-09T09:00:00Z',
                'author' => ['username' => 'sara', 'email' => 'sara@private.example'],
            ]],
        ])->assertOk();

        $stored = json_encode(RepositoryWebhookDelivery::withoutGlobalScopes()->get()->toArray());

        $this->assertStringNotContainsString('sara@private.example', $stored);
    }

    // ------------------------------------------------------------ what it means

    private function mergedPullRequest(): array
    {
        return [
            'action' => 'closed',
            'pull_request' => [
                'number' => 42,
                'title' => 'feat: the development tab',
                'user' => ['login' => 'sara'],
                'head' => ['ref' => 'feature/dev-tab'],
                'base' => ['ref' => 'develop'],
                'state' => 'closed',
                'merged' => true,
                'merged_at' => '2026-10-09T09:30:00Z',
                'merged_by' => ['login' => 'omar'],
                'merge_commit_sha' => str_repeat('b', 40),
                'created_at' => '2026-10-08T09:00:00Z',
                'updated_at' => '2026-10-09T09:30:00Z',
                'html_url' => 'https://github.com/acme/portal/pull/42',
            ],
        ];
    }

    public function test_an_opened_pull_request_appears_with_its_author(): void
    {
        $payload = $this->mergedPullRequest();
        $payload['action'] = 'opened';
        $payload['pull_request']['state'] = 'open';
        $payload['pull_request']['merged'] = false;
        $payload['pull_request']['merged_at'] = null;

        $this->deliver('pull_request', $payload)->assertOk();

        $pr = $this->state(RepositoryPullRequest::class);

        $this->assertSame(42, $pr->number);
        $this->assertSame('open', $pr->state);
        $this->assertSame('sara', $pr->author_login);
        $this->assertSame('develop', $pr->base_branch);
    }

    /** Closed and merged are different outcomes; a boolean would lose one. */
    public function test_a_closed_pull_request_is_not_a_merged_one(): void
    {
        $payload = $this->mergedPullRequest();
        $payload['pull_request']['merged'] = false;
        $payload['pull_request']['merged_at'] = null;

        $this->deliver('pull_request', $payload)->assertOk();

        $this->assertSame('closed', $this->state(RepositoryPullRequest::class)->state);
        $this->assertSame(0, RepositoryActivity::withoutGlobalScopes()->where('kind', 'pr_merged')->count());
        $this->assertSame(1, RepositoryActivity::withoutGlobalScopes()->where('kind', 'pr_closed')->count());
    }

    public function test_a_merge_is_credited_to_whoever_merged_it(): void
    {
        $this->deliver('pull_request', $this->mergedPullRequest())->assertOk();

        $activity = RepositoryActivity::withoutGlobalScopes()->where('kind', 'pr_merged')->firstOrFail();

        $this->assertSame('omar', $activity->actor);
        $this->assertStringContainsString('#42', $activity->title);
    }

    private function workflowRun(array $overrides = []): array
    {
        return [
            'action' => 'completed',
            'workflow_run' => array_merge([
                'id' => 9001,
                'name' => 'Backend tests',
                'event' => 'push',
                'head_branch' => 'feature/dev-tab',
                'head_sha' => str_repeat('c', 40),
                'status' => 'completed',
                'conclusion' => 'success',
                'html_url' => 'https://github.com/acme/portal/actions/runs/9001',
                'run_started_at' => '2026-10-09T09:00:00Z',
                'updated_at' => '2026-10-09T09:05:00Z',
            ], $overrides),
        ];
    }

    public function test_a_finished_run_records_its_outcome(): void
    {
        $this->deliver('workflow_run', $this->workflowRun())->assertOk();

        $this->assertSame('passed', $this->state(RepositoryWorkflowRun::class)->outcome());
        $this->assertSame(1, RepositoryActivity::withoutGlobalScopes()->where('kind', 'ci_passed')->count());
    }

    /**
     * The exact shape of the deployment pipeline that reported green while
     * nothing had shipped: the deploy job was written to skip rather than fail
     * when the server was not configured, and a skipped job reports success.
     */
    public function test_a_skipped_run_is_not_a_passing_one(): void
    {
        $this->deliver('workflow_run', $this->workflowRun(['conclusion' => 'skipped', 'name' => 'Deploy production']))->assertOk();

        $this->assertSame('skipped', $this->state(RepositoryWorkflowRun::class)->outcome());
        $this->assertSame(0, RepositoryActivity::withoutGlobalScopes()->where('kind', 'ci_passed')->count());
    }

    /**
     * Which environment a run is about comes from the project's branch mapping,
     * not from the workflow's name. A workflow called "Deploy production" that
     * ran on a feature branch deployed nothing.
     */
    public function test_the_environment_comes_from_the_branch_not_the_name(): void
    {
        $this->deliver('workflow_run', $this->workflowRun([
            'name' => 'Deploy production',
            'head_branch' => 'feature/dev-tab',
        ]))->assertOk();

        $this->assertNull($this->state(RepositoryWorkflowRun::class)->environment);

        $this->deliver('workflow_run', $this->workflowRun(['id' => 9002, 'head_branch' => 'main']))->assertOk();

        $this->assertSame('production', RepositoryWorkflowRun::withoutGlobalScopes()
            ->where('run_id', 9002)->value('environment'));
    }

    public function test_a_failing_build_shows_on_the_pull_request_it_belongs_to(): void
    {
        $opened = $this->mergedPullRequest();
        $opened['action'] = 'opened';
        $opened['pull_request']['state'] = 'open';
        $opened['pull_request']['merged'] = false;
        $opened['pull_request']['merged_at'] = null;

        $this->deliver('pull_request', $opened)->assertOk();
        $this->deliver('workflow_run', $this->workflowRun(['conclusion' => 'failure']))->assertOk();

        $pr = $this->state(RepositoryPullRequest::class);

        $this->assertSame('failed', $pr->checks_state);
        $this->assertSame('ci_failed', $pr->readiness());
    }

    /** A repository with three workflows is green only when each newest run is. */
    public function test_one_failing_workflow_is_enough_to_say_the_branch_is_red(): void
    {
        $this->deliver('workflow_run', $this->workflowRun(['id' => 1, 'name' => 'Backend', 'conclusion' => 'success']))->assertOk();
        $this->deliver('workflow_run', $this->workflowRun(['id' => 2, 'name' => 'Frontend', 'conclusion' => 'failure']))->assertOk();

        $opened = $this->mergedPullRequest();
        $opened['action'] = 'opened';
        $opened['pull_request']['state'] = 'open';
        $opened['pull_request']['merged'] = false;
        $opened['pull_request']['merged_at'] = null;
        $this->deliver('pull_request', $opened)->assertOk();

        // Re-announce so the PR picks up the branch state it was opened into.
        $this->deliver('workflow_run', $this->workflowRun(['id' => 2, 'name' => 'Frontend', 'conclusion' => 'failure']))->assertOk();

        $this->assertSame('failed', $this->state(RepositoryPullRequest::class)->checks_state);
    }

    /** A comment is not a verdict; treating it as one reads as an approval. */
    public function test_a_comment_review_does_not_approve_anything(): void
    {
        $opened = $this->mergedPullRequest();
        $opened['action'] = 'opened';
        $opened['pull_request']['state'] = 'open';
        $opened['pull_request']['merged'] = false;
        $opened['pull_request']['merged_at'] = null;
        $this->deliver('pull_request', $opened)->assertOk();

        $this->deliver('pull_request_review', [
            'action' => 'submitted',
            'review' => ['state' => 'commented'],
            'pull_request' => ['number' => 42],
        ])->assertOk();

        $this->assertSame('none', $this->state(RepositoryPullRequest::class)->review_state);

        $this->deliver('pull_request_review', [
            'action' => 'submitted',
            'review' => ['state' => 'approved'],
            'pull_request' => ['number' => 42],
        ])->assertOk();

        $this->assertSame('approved', $this->state(RepositoryPullRequest::class)->review_state);
    }

    // ------------------------------------------------------ deployment truth

    /**
     * The distinction the whole feature turns on.
     *
     * GitHub's `success` means the job that deploys finished without error.
     * Nobody has asked the application whether it is answering, and the last
     * pipeline displayed exactly this as "deployed" while nothing had shipped.
     */
    public function test_a_reported_deployment_is_not_a_verified_one(): void
    {
        $this->deliver('deployment_status', [
            'action' => 'created',
            'deployment' => ['id' => 777, 'environment' => 'production', 'ref' => 'main', 'sha' => str_repeat('d', 40)],
            'deployment_status' => ['state' => 'success', 'environment' => 'production', 'updated_at' => '2026-10-09T10:00:00Z'],
        ])->assertOk();

        $deployment = $this->state(RepositoryDeployment::class);

        $this->assertTrue($deployment->reportedLive());
        $this->assertFalse($deployment->isVerified());
        $this->assertSame('reported', $deployment->truth());
    }

    public function test_a_failed_deployment_says_so(): void
    {
        $this->deliver('deployment_status', [
            'deployment' => ['id' => 778, 'environment' => 'production'],
            'deployment_status' => ['state' => 'failure', 'environment' => 'production'],
        ])->assertOk();

        $this->assertSame('failed', $this->state(RepositoryDeployment::class)->truth());
        $this->assertSame(1, RepositoryActivity::withoutGlobalScopes()->where('kind', 'deploy_failed')->count());
    }

    public function test_a_published_release_is_recorded(): void
    {
        $this->deliver('release', [
            'action' => 'published',
            'release' => [
                'id' => 555,
                'tag_name' => 'v2.0.0',
                'name' => 'النسخة الثانية',
                'target_commitish' => 'main',
                'author' => ['login' => 'omar'],
                'html_url' => 'https://github.com/acme/portal/releases/tag/v2.0.0',
                'published_at' => '2026-10-09T11:00:00Z',
            ],
        ])->assertOk();

        $this->assertSame('v2.0.0', $this->state(RepositoryRelease::class)->tag_name);
        $this->assertSame(1, RepositoryActivity::withoutGlobalScopes()->where('kind', 'released')->count());
    }

    /** A draft release has not been released. */
    public function test_a_release_that_is_only_created_is_not_published(): void
    {
        $this->deliver('release', [
            'action' => 'created',
            'release' => ['id' => 556, 'tag_name' => 'v2.1.0', 'html_url' => 'x'],
        ])->assertOk();

        $this->assertSame(0, RepositoryRelease::withoutGlobalScopes()->count());
    }

    // -------------------------------------------------------------- commits

    public function test_a_push_to_a_mapped_branch_becomes_timeline_lines(): void
    {
        $this->deliver('push', [
            'ref' => 'refs/heads/main',
            'commits' => [
                ['id' => str_repeat('1', 40), 'message' => "feat: one\n\nbody", 'timestamp' => '2026-10-09T09:00:00Z', 'author' => ['username' => 'sara']],
                ['id' => str_repeat('2', 40), 'message' => 'fix: two', 'timestamp' => '2026-10-09T09:01:00Z', 'author' => ['username' => 'omar']],
            ],
        ])->assertOk();

        $commits = RepositoryActivity::withoutGlobalScopes()->where('kind', 'commit')->get();

        $this->assertCount(2, $commits);
        // The first line only: a timeline is not a place to read commit bodies.
        $this->assertSame('feat: one', $commits->firstWhere('actor', 'sara')->title);
    }

    /**
     * Forty commits to a feature branch are not forty things a project manager
     * needs to read. Only what reached staging or production is recorded.
     */
    public function test_a_push_to_an_unmapped_branch_is_not_timeline_material(): void
    {
        $this->deliver('push', [
            'ref' => 'refs/heads/feature/noise',
            'commits' => [['id' => str_repeat('3', 40), 'message' => 'wip', 'timestamp' => '2026-10-09T09:00:00Z']],
        ])->assertOk();

        $this->assertSame(0, RepositoryActivity::withoutGlobalScopes()->where('kind', 'commit')->count());
    }

    public function test_the_same_commit_arriving_twice_is_one_line(): void
    {
        $push = [
            'ref' => 'refs/heads/main',
            'commits' => [['id' => str_repeat('4', 40), 'message' => 'feat: once', 'timestamp' => '2026-10-09T09:00:00Z']],
        ];

        $this->deliver('push', $push)->assertOk();
        $this->deliver('push', $push)->assertOk();

        $this->assertSame(1, RepositoryActivity::withoutGlobalScopes()->where('kind', 'commit')->count());
    }

    // ------------------------------------------------------------- tenancy

    public function test_a_delivery_writes_only_into_its_own_workspace(): void
    {
        $other = $this->provisionTenant('Somebody Else');

        $this->deliver('pull_request', $this->mergedPullRequest())->assertOk();

        $this->assertSame(
            $this->t['tenant']->id,
            RepositoryPullRequest::withoutGlobalScopes()->first()?->tenant_id,
        );
        $this->assertSame(0, RepositoryPullRequest::withoutGlobalScopes()
            ->where('tenant_id', $other['tenant']->id)->count());
    }

    public function test_an_event_nobody_asked_for_is_recorded_and_ignored(): void
    {
        $this->deliver('issues', ['action' => 'opened', 'issue' => ['number' => 1]])->assertOk();

        $this->assertSame(
            RepositoryWebhookDelivery::IGNORED,
            RepositoryWebhookDelivery::withoutGlobalScopes()->first()?->status,
        );
    }
}
