<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\User;
use App\Notifications\RepositoryEventNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Who is told when something happens to the code, and who is not.
 *
 * Four events worth interrupting somebody for, and everything else left on the
 * screen. A notification per push is a notification people turn off, and the
 * one that mattered goes with it.
 */
class TheBuildBrokeAndSomebodyKnowsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private ProjectRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alarm Co');

        $connection = $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'github', 'name' => 'GitHub', 'status' => Integration::CONNECTED,
            'credentials' => ['access_token' => 't'], 'is_active' => true,
        ]));

        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'بوابة']));

        $this->repository = $this->asTenant($this->t['tenant'], fn () => ProjectRepository::create([
            'project_id' => $project->id, 'integration_id' => $connection->id, 'provider' => 'github',
            'repository_id' => 1, 'repository_full_name' => 'acme/portal', 'default_branch' => 'main',
            'develop_branch' => 'develop', 'production_branch' => 'main',
            'webhook_key' => (string) Str::uuid(), 'webhook_secret' => 'secret', 'webhook_status' => 'ok',
        ]));
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

    private function failedBuild(?string $at = null): void
    {
        $this->deliver('workflow_run', ['action' => 'completed', 'workflow_run' => [
            'id' => random_int(1, 10_000_000), 'name' => 'Backend tests', 'event' => 'push',
            'head_branch' => 'main', 'head_sha' => str_repeat('a', 40),
            'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'u',
            'run_started_at' => $at ?? now()->subMinutes(2)->toIso8601String(),
            'updated_at' => $at ?? now()->subMinutes(2)->toIso8601String(),
        ]]);
    }

    public function test_a_broken_build_reaches_the_people_who_can_act_on_it(): void
    {
        Notification::fake();
        $manager = $this->makeUser($this->t['tenant'], Role::Manager);
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->failedBuild();

        Notification::assertSentTo($manager, RepositoryEventNotification::class);
        Notification::assertSentTo($this->t['admin'], RepositoryEventNotification::class);
        // A build that broke is a manager's problem and a rep's noise.
        Notification::assertNotSentTo($rep, RepositoryEventNotification::class);
    }

    /** A notification per push is one people turn off, taking the rest with it. */
    public function test_an_ordinary_commit_interrupts_nobody(): void
    {
        Notification::fake();

        $this->deliver('push', [
            'ref' => 'refs/heads/main',
            'commits' => [['id' => str_repeat('b', 40), 'message' => 'chore: tidy', 'timestamp' => now()->toIso8601String()]],
        ]);

        Notification::assertNothingSent();
    }

    public function test_a_passing_build_interrupts_nobody(): void
    {
        Notification::fake();

        $this->deliver('workflow_run', ['action' => 'completed', 'workflow_run' => [
            'id' => 1, 'name' => 'Backend tests', 'head_branch' => 'main', 'head_sha' => 'a',
            'status' => 'completed', 'conclusion' => 'success', 'html_url' => 'u',
            'updated_at' => now()->toIso8601String(),
        ]]);

        Notification::assertNothingSent();
    }

    /**
     * A repair catching up on last March must not page anybody about it. The
     * notification nobody can act on is how people learn to ignore the one they
     * can.
     */
    public function test_catching_up_on_something_old_tells_nobody(): void
    {
        Notification::fake();

        $this->failedBuild(now()->subDays(30)->toIso8601String());

        Notification::assertNothingSent();
    }

    /** Re-delivery is not news. */
    public function test_being_told_the_same_thing_twice_notifies_once(): void
    {
        Notification::fake();

        $payload = ['action' => 'completed', 'workflow_run' => [
            'id' => 4242, 'name' => 'Backend tests', 'head_branch' => 'main', 'head_sha' => 'a',
            'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'u',
            'updated_at' => now()->toIso8601String(),
        ]];

        $this->deliver('workflow_run', $payload);
        $this->deliver('workflow_run', $payload);

        Notification::assertSentToTimes($this->t['admin'], RepositoryEventNotification::class, 1);
    }

    public function test_a_failed_deployment_reaches_somebody(): void
    {
        Notification::fake();

        $this->deliver('deployment_status', [
            'deployment' => ['id' => 9, 'environment' => 'production', 'ref' => 'main'],
            'deployment_status' => ['state' => 'failure', 'environment' => 'production', 'updated_at' => now()->toIso8601String()],
        ]);

        Notification::assertSentTo($this->t['admin'], RepositoryEventNotification::class);
    }

    /** A staging deploy is a Tuesday. */
    public function test_a_staging_deploy_is_not_worth_interrupting_anybody(): void
    {
        Notification::fake();

        $this->deliver('deployment_status', [
            'deployment' => ['id' => 10, 'environment' => 'staging', 'ref' => 'develop'],
            'deployment_status' => ['state' => 'success', 'environment' => 'staging', 'updated_at' => now()->toIso8601String()],
        ]);

        Notification::assertNothingSent();
    }

    /**
     * A merge is not an action a مهتم user took. Attributing it to whoever
     * happened to be signed in would make the trail a work of fiction the first
     * time somebody audited a deployment.
     */
    public function test_github_events_are_recorded_as_coming_from_github(): void
    {
        Notification::fake();

        $this->failedBuild();

        $entry = AuditLog::withoutGlobalScopes()->where('action', 'github.ci_failed')->first();

        $this->assertNotNull($entry);
        $this->assertNull($entry->user_id);
        $this->assertSame('github', $entry->changes['source'] ?? null);
    }

    public function test_an_away_manager_is_not_woken_up(): void
    {
        Notification::fake();

        $away = $this->makeUser($this->t['tenant'], Role::Manager);
        $away->forceFill(['away_until' => now()->addWeek()])->save();

        $this->failedBuild();

        Notification::assertNotSentTo($away, RepositoryEventNotification::class);
    }

    public function test_another_workspace_is_never_told(): void
    {
        Notification::fake();

        $other = $this->provisionTenant('Somebody Else');

        $this->failedBuild();

        Notification::assertNotSentTo($other['admin'], RepositoryEventNotification::class);
    }

    public function test_the_notification_says_a_deployment_is_unverified(): void
    {
        $project = Project::withoutGlobalScopes()->firstOrFail();

        $payload = (new RepositoryEventNotification($project, RepositoryEventNotification::DEPLOYED, 'production'))
            ->toDatabase(User::withoutGlobalScopes()->firstOrFail());

        // The product must never imply somebody checked when nobody did.
        $this->assertStringContainsString('لم يُتحقَّق منه', $payload['body']);
    }
}
