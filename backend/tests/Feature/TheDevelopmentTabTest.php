<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Integration;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\RepositoryActivity;
use App\Models\RepositoryDeployment;
use App\Models\RepositoryPullRequest;
use App\Models\RepositoryRelease;
use App\Models\RepositoryWorkflowRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What a project manager sees, and what they are not told.
 *
 * The five questions somebody running a project asks — what changed, who
 * merged it, is the build green, did it reach the server, what version is live
 * — and one answer that has to be exactly right: a deployment nobody verified
 * is never shown as live.
 */
class TheDevelopmentTabTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Project $project;

    private ProjectRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Dev Tab Co');

        $connection = $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'github', 'name' => 'GitHub', 'status' => Integration::CONNECTED,
            'credentials' => ['access_token' => 't'], 'is_active' => true,
        ]));

        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'بوابة']));

        $this->repository = $this->asTenant($this->t['tenant'], fn () => ProjectRepository::create([
            'project_id' => $this->project->id,
            'integration_id' => $connection->id,
            'provider' => 'github',
            'repository_id' => 4242,
            'repository_full_name' => 'acme/portal',
            'default_branch' => 'main',
            'develop_branch' => 'develop',
            'production_branch' => 'main',
            'webhook_status' => 'ok',
            'webhook_key' => (string) Str::uuid(),
        ]));
    }

    /**
     * Not `view()`: Laravel's TestCase already has one, and a private override
     * is a fatal error at class load that takes every other test in the run
     * with it — the same trap `run()` set earlier in this codebase.
     */
    private function development(?User $as = null): array
    {
        return $this->actingAs($as ?? $this->t['admin'])
            ->getJson("/api/v1/projects/{$this->project->uuid}/development")
            ->assertOk()
            ->json('data');
    }

    public function test_a_project_with_no_repository_says_so_plainly(): void
    {
        $this->repository->delete();

        $this->assertNull($this->development()['repository']);
    }

    /**
     * Opening the screen never calls GitHub. A page that spent API budget to
     * render would be the first thing to exhaust the budget everything else
     * needs — and it would be slow in exactly the moment somebody is watching.
     */
    public function test_opening_it_never_calls_github(): void
    {
        Http::fake();

        $this->development();

        Http::assertNothingSent();
    }

    public function test_it_shows_the_build_for_each_environment(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            RepositoryWorkflowRun::create([
                'project_repository_id' => $this->repository->id, 'run_id' => 1,
                'name' => 'Tests', 'branch' => 'main', 'status' => 'completed',
                'conclusion' => 'success', 'environment' => 'production', 'html_url' => 'u',
            ]);
            RepositoryWorkflowRun::create([
                'project_repository_id' => $this->repository->id, 'run_id' => 2,
                'name' => 'Tests', 'branch' => 'develop', 'status' => 'completed',
                'conclusion' => 'failure', 'environment' => 'staging', 'html_url' => 'u',
            ]);
        });

        $ci = $this->development()['ci'];

        $this->assertSame('passed', $ci['production']['outcome']);
        $this->assertSame('failed', $ci['staging']['outcome']);
    }

    /** The newest run wins, not whichever event arrived last. */
    public function test_an_older_run_does_not_overrule_a_newer_one(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            RepositoryWorkflowRun::create([
                'project_repository_id' => $this->repository->id, 'run_id' => 10,
                'name' => 'Tests', 'branch' => 'main', 'status' => 'completed',
                'conclusion' => 'failure', 'html_url' => 'u',
            ]);
            RepositoryWorkflowRun::create([
                'project_repository_id' => $this->repository->id, 'run_id' => 11,
                'name' => 'Tests', 'branch' => 'main', 'status' => 'completed',
                'conclusion' => 'success', 'html_url' => 'u',
            ]);
        });

        $this->assertSame('passed', $this->development()['ci']['production']['outcome']);
    }

    /**
     * The answer the whole feature exists to get right.
     */
    public function test_a_deployment_nobody_verified_is_not_shown_as_live(): void
    {
        $this->asTenant($this->t['tenant'], fn () => RepositoryDeployment::create([
            'project_repository_id' => $this->repository->id,
            'deployment_id' => 1, 'environment' => 'production', 'state' => 'success',
        ]));

        $deployment = $this->development()['deployments'][0];

        $this->assertSame('success', $deployment['state']);
        $this->assertSame('reported', $deployment['truth']);
        $this->assertNull($deployment['verified_at']);
    }

    public function test_only_the_newest_deployment_per_environment_is_shown(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            foreach ([1, 2, 3] as $id) {
                RepositoryDeployment::create([
                    'project_repository_id' => $this->repository->id,
                    'deployment_id' => $id, 'environment' => 'production',
                    'state' => $id === 3 ? 'success' : 'failure',
                ]);
            }
        });

        $deployments = $this->development()['deployments'];

        $this->assertCount(1, $deployments);
        $this->assertSame('reported', $deployments[0]['truth']);
    }

    public function test_open_pull_requests_come_before_closed_ones(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            RepositoryPullRequest::create([
                'project_repository_id' => $this->repository->id, 'number' => 1,
                'title' => 'merged one', 'head_branch' => 'a', 'base_branch' => 'develop',
                'state' => 'merged', 'html_url' => 'u', 'updated_at_github' => now(),
            ]);
            RepositoryPullRequest::create([
                'project_repository_id' => $this->repository->id, 'number' => 2,
                'title' => 'open one', 'head_branch' => 'b', 'base_branch' => 'develop',
                'state' => 'open', 'checks_state' => 'passed', 'review_state' => 'approved',
                'html_url' => 'u', 'updated_at_github' => now()->subDay(),
            ]);
        });

        $prs = $this->development()['pull_requests'];

        $this->assertSame(2, $prs[0]['number']);
        $this->assertSame('ready_to_merge', $prs[0]['readiness']);
    }

    /** Approving a branch that does not build is a review of something that cannot ship. */
    public function test_a_failing_build_outranks_an_approval(): void
    {
        $this->asTenant($this->t['tenant'], fn () => RepositoryPullRequest::create([
            'project_repository_id' => $this->repository->id, 'number' => 3,
            'title' => 'approved but red', 'head_branch' => 'c', 'base_branch' => 'develop',
            'state' => 'open', 'checks_state' => 'failed', 'review_state' => 'approved',
            'html_url' => 'u',
        ]));

        $this->assertSame('ci_failed', $this->development()['pull_requests'][0]['readiness']);
    }

    public function test_it_shows_the_latest_published_release(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            RepositoryRelease::create([
                'project_repository_id' => $this->repository->id, 'release_id' => 1,
                'tag_name' => 'v1.0.0', 'html_url' => 'u', 'published_at' => now()->subWeek(),
            ]);
            RepositoryRelease::create([
                'project_repository_id' => $this->repository->id, 'release_id' => 2,
                'tag_name' => 'v2.0.0', 'html_url' => 'u', 'published_at' => now(),
            ]);
        });

        $this->assertSame('v2.0.0', $this->development()['release']['tag']);
    }

    public function test_the_timeline_is_newest_first(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            RepositoryActivity::create([
                'project_repository_id' => $this->repository->id, 'kind' => 'commit',
                'title' => 'older', 'commit_sha' => 'a', 'ref' => 'main', 'happened_at' => now()->subHour(),
            ]);
            RepositoryActivity::create([
                'project_repository_id' => $this->repository->id, 'kind' => 'released',
                'title' => 'newer', 'commit_sha' => '', 'ref' => 'v1', 'happened_at' => now(),
            ]);
        });

        $this->assertSame('newer', $this->development()['activity'][0]['title']);
    }

    /** A rep can open a project; what is in a repository is not a customer's data. */
    public function test_a_rep_may_read_the_development_view(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->assertSame('acme/portal', $this->development($rep)['repository']['full_name']);
    }

    public function test_another_workspace_cannot_read_it(): void
    {
        $other = $this->provisionTenant('Somebody Else');

        $this->actingAs($other['admin'])
            ->getJson("/api/v1/projects/{$this->project->uuid}/development")
            ->assertNotFound();
    }
}
