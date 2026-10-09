<?php

namespace Tests\Feature;

use App\Domain\Git\GitHub\GitHubClient;
use App\Enums\IntegrationProvider;
use App\Enums\Role;
use App\Models\Integration;
use App\Models\Project;
use App\Models\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Connecting a workspace to its own GitHub, and a project to its repository.
 *
 * Everything here is mocked at the HTTP boundary and nowhere above it: the
 * client, the provider and the controller are the real ones, because the bugs
 * in an integration live in exactly those seams — a header read as the wrong
 * type, a 403 that meant two different things, a token that reached a log.
 */
class TheRepositoryIsConnectedTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Repo Co');
        Cache::flush();
    }

    private function repoPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => 4242,
            'full_name' => 'acme/portal',
            'default_branch' => 'main',
            'private' => true,
            'description' => 'The portal',
            'pushed_at' => '2026-10-01T10:00:00Z',
        ], $overrides);
    }

    /** A GitHub that answers every call this flow makes. */
    private function fakeGitHub(array $overrides = []): void
    {
        Http::fake(array_merge([
            'api.github.com/user' => Http::response(['login' => 'acme-bot'], 200, ['x-ratelimit-remaining' => '4999', 'x-ratelimit-limit' => '5000']),
            'api.github.com/user/repos*' => Http::response([$this->repoPayload()], 200, ['x-ratelimit-remaining' => '4998']),
            'api.github.com/repos/acme/portal' => Http::response($this->repoPayload(), 200, ['x-ratelimit-remaining' => '4997']),
            'api.github.com/repos/acme/portal/branches*' => Http::response([
                ['name' => 'main'], ['name' => 'develop'], ['name' => 'feature/x'],
            ], 200),
            'api.github.com/repos/acme/portal/actions/workflows*' => Http::response([
                'workflows' => [['id' => 1, 'name' => 'Deploy production', 'path' => '.github/workflows/deploy-production.yml', 'state' => 'active']],
            ], 200),
            'api.github.com/repos/acme/portal/environments' => Http::response(['environments' => [['name' => 'production']]], 200),
            'api.github.com/repos/acme/portal/releases/latest' => Http::response(['tag_name' => 'v1.4.0'], 200),
            'api.github.com/repos/acme/portal/hooks' => Http::response([], 200),
        ], $overrides));
    }

    private function connect(?string $token = 'github_pat_11ABCDEFG0123456789'): TestResponse
    {
        return $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/integrations/github', ['token' => $token]);
    }

    // ----------------------------------------------------------- connecting

    public function test_a_token_is_checked_before_it_is_believed(): void
    {
        $this->fakeGitHub();

        $this->connect()
            ->assertOk()
            ->assertJsonPath('data.connection.state', 'connected')
            ->assertJsonPath('data.connection.account', 'acme-bot');
    }

    /**
     * A connection screen that says "connected" about a credential nobody has
     * tried is the one state an integration must never show.
     */
    public function test_a_token_github_refuses_does_not_become_a_connection(): void
    {
        $this->fakeGitHub(['api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401)]);

        $this->connect()->assertStatus(422)->assertJsonValidationErrors('token');

        $connection = Integration::withoutGlobalScopes()
            ->where('provider', IntegrationProvider::GitHub->value)
            ->first();

        // The row is kept with the reason on it, so the screen can say "that
        // token was refused" rather than silently returning to a disconnected
        // state the user cannot learn anything from.
        $this->assertNotNull($connection);
        $this->assertSame('auth_failed', $connection->last_error);
        $this->assertSame(Integration::ERROR, $connection->status);
    }

    /** Written, never read back: the screen says whether there is one. */
    public function test_the_token_never_comes_back_out(): void
    {
        $this->fakeGitHub();
        $secret = 'github_pat_11SECRETVALUE0000000';

        $payload = $this->connect($secret)->assertOk()->json();

        $this->assertStringNotContainsString($secret, json_encode($payload));
        $this->assertTrue($payload['data']['connection']['has_token']);

        // And not from the listing either.
        $listed = $this->actingAs($this->t['admin'])->getJson('/api/v1/integrations/github')->json();
        $this->assertStringNotContainsString($secret, json_encode($listed));
    }

    public function test_it_is_stored_encrypted_rather_than_as_text(): void
    {
        $this->fakeGitHub();
        $secret = 'github_pat_11SECRETVALUE0000000';

        $this->connect($secret)->assertOk();

        $raw = \DB::table('integrations')->where('provider', 'github')->value('credentials');

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString($secret, (string) $raw);
    }

    public function test_a_rep_may_not_connect_a_workspace_to_github(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->actingAs($rep)
            ->postJson('/api/v1/integrations/github', ['token' => 'github_pat_11ABCDEFG0123456789'])
            ->assertForbidden();
    }

    /**
     * Disconnecting drops the credential and keeps the mappings. "This token
     * was rotated" is the ordinary reason, and losing which repository every
     * project is built in is not recoverable from that screen.
     */
    public function test_disconnecting_forgets_the_token_and_keeps_the_mapping(): void
    {
        $this->fakeGitHub();
        $this->connect()->assertOk();
        $project = $this->linkProject();

        $this->actingAs($this->t['admin'])->deleteJson('/api/v1/integrations/github')->assertOk();

        $this->assertDatabaseHas('project_repositories', ['project_id' => $project->id]);

        $connection = Integration::withoutGlobalScopes()->where('provider', 'github')->first();
        $this->assertSame([], $connection->credentials);
        $this->assertFalse($connection->is_active);
    }

    // ------------------------------------------------------------- mapping

    private function linkProject(array $body = []): Project
    {
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'بوابة العملاء']));

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/projects/{$project->uuid}/repository", $body + ['full_name' => 'acme/portal'])
            ->assertOk();

        return $project;
    }

    public function test_linking_a_project_reads_the_repository_rather_than_asking(): void
    {
        $this->fakeGitHub();
        $this->connect()->assertOk();

        $project = $this->linkProject();

        $mapping = ProjectRepository::withoutGlobalScopes()->where('project_id', $project->id)->firstOrFail();

        $this->assertSame(4242, $mapping->repository_id);
        $this->assertSame('acme/portal', $mapping->repository_full_name);
        $this->assertSame('main', $mapping->default_branch);
        // develop and main exist, so they are suggested as staging and production.
        $this->assertSame('develop', $mapping->develop_branch);
        $this->assertSame('main', $mapping->production_branch);
    }

    /** The user's answer wins over ours: a workspace whose staging is `qa`. */
    public function test_a_branch_the_user_names_is_not_overruled(): void
    {
        $this->fakeGitHub();
        $this->connect()->assertOk();

        $project = $this->linkProject(['develop_branch' => 'qa']);

        $this->assertSame('qa', ProjectRepository::withoutGlobalScopes()
            ->where('project_id', $project->id)->value('develop_branch'));
    }

    /**
     * The id, not the name, is what the mapping is keyed on — a repository
     * renamed next month still matches, and every webhook after that still
     * finds its project.
     */
    public function test_a_renamed_repository_is_still_the_same_repository(): void
    {
        $this->fakeGitHub();
        $this->connect()->assertOk();
        $project = $this->linkProject();

        $mapping = ProjectRepository::withoutGlobalScopes()->where('project_id', $project->id)->firstOrFail();

        $this->assertSame(
            $mapping->repository_id,
            ProjectRepository::withoutGlobalScopes()->where('repository_id', 4242)->value('repository_id'),
        );
    }

    public function test_a_repository_the_token_cannot_see_is_refused_with_a_reason(): void
    {
        $this->fakeGitHub(['api.github.com/repos/acme/portal' => Http::response(['message' => 'Not Found'], 404)]);
        $this->connect()->assertOk();

        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'مشروع']));

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/projects/{$project->uuid}/repository", ['full_name' => 'acme/portal'])
            ->assertStatus(422)
            ->assertJsonPath('errors.full_name.0', 'github.not_found');
    }

    public function test_nothing_can_be_linked_before_the_workspace_connects(): void
    {
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'مشروع']));

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/projects/{$project->uuid}/repository", ['full_name' => 'acme/portal'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('connection');
    }

    public function test_one_workspace_cannot_map_another_workspaces_project(): void
    {
        $this->fakeGitHub();
        $this->connect()->assertOk();

        $other = $this->provisionTenant('Somebody Else');
        $theirs = $this->asTenant($other['tenant'], fn () => Project::create(['name' => 'ليس لك']));

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/projects/{$theirs->uuid}/repository", ['full_name' => 'acme/portal'])
            ->assertNotFound();
    }

    public function test_one_workspace_never_sees_another_workspaces_connection(): void
    {
        $this->fakeGitHub();
        $this->connect()->assertOk();

        $other = $this->provisionTenant('Somebody Else');

        $this->actingAs($other['admin'])
            ->getJson('/api/v1/integrations/github')
            ->assertOk()
            ->assertJsonPath('data.connection.state', 'disconnected');
    }

    // -------------------------------------------------------- the webhook

    /**
     * Set up for the workspace rather than printed as an instruction.
     *
     * An integration whose first step is "now go and paste this into GitHub" is
     * one most workspaces never finish, and a repository that was linked but
     * never wired up looks connected while telling us nothing.
     */
    public function test_linking_asks_github_to_start_telling_us_things(): void
    {
        $this->fakeGitHub([
            'api.github.com/repos/acme/portal/hooks' => Http::sequence()
                ->push([], 200)
                ->push(['id' => 31337], 201),
        ]);
        $this->connect()->assertOk();

        $project = $this->linkProject();
        $mapping = ProjectRepository::withoutGlobalScopes()->where('project_id', $project->id)->firstOrFail();

        $this->assertSame('ok', $mapping->webhook_status);
        $this->assertSame(31337, $mapping->webhook_id);
        $this->assertNotNull($mapping->webhook_key);

        // Asked for the events we reduce, and not for `*`: every comment, star
        // and issue edit on a busy repository would otherwise arrive at a URL
        // that throws them away.
        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/hooks')) {
                return false;
            }

            $events = $request->data()['events'] ?? [];

            return in_array('pull_request', $events, true)
                && in_array('workflow_run', $events, true)
                && in_array('deployment_status', $events, true)
                && ! in_array('*', $events, true);
        });
    }

    /** The secret signs the body; it is sent to GitHub and never shown again. */
    public function test_the_webhook_secret_is_not_handed_back_to_the_screen(): void
    {
        $this->fakeGitHub([
            'api.github.com/repos/acme/portal/hooks' => Http::sequence()->push([], 200)->push(['id' => 1], 201),
        ]);
        $this->connect()->assertOk();

        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'بوابة']));

        $body = $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/projects/{$project->uuid}/repository", ['full_name' => 'acme/portal'])
            ->assertOk()
            ->json();

        $secret = ProjectRepository::withoutGlobalScopes()->where('project_id', $project->id)->value('webhook_secret');

        $this->assertNotEmpty($secret);
        $this->assertStringNotContainsString($secret, json_encode($body));
        // The URL is shown, because a workspace that must add the hook by hand
        // needs somewhere to point it.
        $this->assertStringContainsString('/integrations/github/webhook/', $body['data']['repository']['webhook']['url']);
    }

    /**
     * A read-only token is a reasonable thing for a careful workspace to issue.
     * It is not an error — it is a different setup path, and the readiness
     * panel has to say which.
     */
    public function test_a_token_that_may_not_manage_hooks_is_partial_not_broken(): void
    {
        $this->fakeGitHub([
            'api.github.com/repos/acme/portal/hooks' => Http::response(['message' => 'Resource not accessible'], 403, ['x-ratelimit-remaining' => '900']),
        ]);
        $this->connect()->assertOk();

        $project = $this->linkProject();
        $mapping = ProjectRepository::withoutGlobalScopes()->where('project_id', $project->id)->firstOrFail();

        $this->assertSame('manual', $mapping->webhook_status);

        $readiness = $mapping->readiness();
        $this->assertSame('partial', $readiness['state']);
        $this->assertContains('webhook_manual', $readiness['reasons']);
    }

    public function test_a_fully_wired_repository_reads_as_ready(): void
    {
        $this->fakeGitHub([
            'api.github.com/repos/acme/portal/hooks' => Http::sequence()->push([], 200)->push(['id' => 7], 201),
        ]);
        $this->connect()->assertOk();

        $project = $this->linkProject();
        $mapping = ProjectRepository::withoutGlobalScopes()->where('project_id', $project->id)->firstOrFail();

        $this->assertSame('ready', $mapping->readiness()['state']);
    }

    /** A hook left behind keeps posting to a key that no longer resolves. */
    public function test_unlinking_takes_the_webhook_with_it(): void
    {
        $this->fakeGitHub([
            'api.github.com/repos/acme/portal/hooks' => Http::sequence()->push([], 200)->push(['id' => 99], 201),
            'api.github.com/repos/acme/portal/hooks/99' => Http::response('', 204),
        ]);
        $this->connect()->assertOk();
        $project = $this->linkProject();

        $this->actingAs($this->t['admin'])
            ->deleteJson("/api/v1/projects/{$project->uuid}/repository")
            ->assertOk();

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/hooks/99'));

        $this->assertSame(0, ProjectRepository::withoutGlobalScopes()->where('project_id', $project->id)->count());
    }

    // -------------------------------------------------------- the budget

    /**
     * GitHub gives one hourly budget per token and stops answering for
     * everything when it is gone, so what is left is read off every response
     * and kept where the health screen can see it without spending one to ask.
     */
    public function test_what_is_left_of_the_hour_is_remembered(): void
    {
        $this->fakeGitHub();
        $this->connect()->assertOk();

        $connection = Integration::withoutGlobalScopes()->where('provider', 'github')->firstOrFail();
        $budget = app(GitHubClient::class)->budget($connection);

        $this->assertSame(4999, $budget['remaining']);
        $this->assertSame(5000, $budget['limit']);
        $this->assertFalse($budget['low']);
    }

    public function test_a_budget_running_out_is_flagged_before_it_does(): void
    {
        $this->fakeGitHub([
            'api.github.com/user' => Http::response(['login' => 'acme-bot'], 200, [
                'x-ratelimit-remaining' => '12', 'x-ratelimit-limit' => '5000',
            ]),
        ]);
        $this->connect()->assertOk();

        $connection = Integration::withoutGlobalScopes()->where('provider', 'github')->firstOrFail();

        $this->assertTrue(app(GitHubClient::class)->budget($connection)['low']);
    }

    /**
     * A 403 is two different problems wearing one status: a missing scope, or
     * an exhausted budget. Telling somebody to reconnect when they only need to
     * wait is the worse mistake, so the headers decide.
     */
    public function test_a_spent_budget_is_not_reported_as_a_broken_token(): void
    {
        $this->fakeGitHub([
            'api.github.com/user' => Http::response(['message' => 'API rate limit exceeded'], 403, [
                'x-ratelimit-remaining' => '0',
                'x-ratelimit-reset' => (string) (time() + 600),
            ]),
        ]);

        $this->connect()->assertStatus(422)->assertJsonPath('errors.token.0', 'github.rate_limited');
    }

    public function test_a_missing_scope_is_not_reported_as_a_spent_budget(): void
    {
        $this->fakeGitHub([
            'api.github.com/user' => Http::response(['message' => 'Resource not accessible'], 403, [
                'x-ratelimit-remaining' => '4000',
            ]),
        ]);

        $this->connect()->assertStatus(422)->assertJsonPath('errors.token.0', 'github.forbidden');
    }

    /**
     * The thing that makes a sweep every ten minutes affordable.
     *
     * A resource that has not changed answers 304 to a request carrying its
     * ETag, and a 304 costs nothing against the hourly budget. Without this,
     * reconciliation would spend the token on being told the same thing all
     * day.
     */
    public function test_it_does_not_ask_twice_for_something_that_has_not_changed(): void
    {
        $this->fakeGitHub([
            'api.github.com/user' => Http::sequence()
                ->push(['login' => 'acme-bot'], 200, ['ETag' => 'W/"abc123"', 'x-ratelimit-remaining' => '4999'])
                ->push('', 304, ['x-ratelimit-remaining' => '4999']),
        ]);

        $this->connect()->assertOk();

        $connection = Integration::withoutGlobalScopes()->where('provider', 'github')->firstOrFail();
        $second = app(GitHubClient::class)->get($connection, '/user');

        $this->assertTrue($second['unchanged']);
        $this->assertNull($second['error']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.github.com/user'
                && $request->hasHeader('If-None-Match', 'W/"abc123"');
        });
    }

    // --------------------------------------------------------- discovery

    public function test_it_offers_what_the_repository_already_knows(): void
    {
        $this->fakeGitHub();
        $this->connect()->assertOk();

        $discovery = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/integrations/github/discover?full_name=acme/portal')
            ->assertOk()
            ->json('data.discovery');

        $this->assertSame(['main', 'develop', 'feature/x'], $discovery['branches']);
        $this->assertSame('Deploy production', $discovery['workflows'][0]['name']);
        $this->assertSame(['production'], $discovery['environments']);
        $this->assertSame('v1.4.0', $discovery['latest_release']);
        $this->assertSame('develop', $discovery['suggested']['develop_branch']);
    }

    /**
     * "Actions are disabled here" and "this token may not read workflows" both
     * answer with nothing, and only one of them is the user's problem — so the
     * reason is kept rather than flattened into an empty list.
     */
    public function test_a_thing_we_were_not_allowed_to_read_is_not_reported_as_absent(): void
    {
        $this->fakeGitHub([
            'api.github.com/repos/acme/portal/actions/workflows*' => Http::response(['message' => 'Resource not accessible'], 403, ['x-ratelimit-remaining' => '900']),
        ]);
        $this->connect()->assertOk();

        $discovery = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/integrations/github/discover?full_name=acme/portal')
            ->assertOk()
            ->json('data.discovery');

        $this->assertSame([], $discovery['workflows']);
        $this->assertSame('forbidden', $discovery['unavailable']['workflows']);
    }

    /** A repository nobody has released is a fact about it, not a failure. */
    public function test_never_having_released_is_not_an_error(): void
    {
        $this->fakeGitHub([
            'api.github.com/repos/acme/portal/releases/latest' => Http::response(['message' => 'Not Found'], 404),
        ]);
        $this->connect()->assertOk();

        $discovery = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/integrations/github/discover?full_name=acme/portal')
            ->assertOk()
            ->json('data.discovery');

        $this->assertNull($discovery['latest_release']);
        $this->assertArrayNotHasKey('latest_release', $discovery['unavailable']);
    }

    public function test_something_that_is_not_a_repository_name_is_refused(): void
    {
        $this->fakeGitHub();
        $this->connect()->assertOk();

        $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/integrations/github/discover?full_name='.urlencode('../../etc/passwd'))
            ->assertStatus(422);
    }
}
