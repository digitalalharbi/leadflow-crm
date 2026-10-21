<?php

namespace Tests\Feature;

use App\Domain\Access\Permissions;
use App\Domain\Access\RoutePermission;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use App\Models\Project;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Roles, permissions, project scope and what a response may contain — and that the API refuses
 * what the interface hides.
 */
class RolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Project $a;

    private Project $b;

    private Lead $inA;

    private Lead $inB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Roles Co');

        [$this->a, $this->b] = $this->asTenant($this->t['tenant'], fn () => [Project::create(['name' => 'النرجس']), Project::create(['name' => 'الياسمين'])]);

        $this->inA = $this->lead($this->a, 'عميل النرجس');
        $this->inB = $this->lead($this->b, 'عميل الياسمين');
    }

    private function lead(Project $project, string $name): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => $name, 'phone' => '0551234567', 'phone_normalized' => '+9665512'.random_int(10000, 99999), 'email' => str()->random(6).'@example.test',
            'project_id' => $project->id, 'status' => 'contacted', 'deal_value_minor' => 90_000_000,
        ]));
    }

    private function user(Role $role, array $projects = []): User
    {
        $user = $this->makeUser($this->t['tenant'], $role);
        $this->asTenant($this->t['tenant'], fn () => $user->projects()->sync(collect($projects)->pluck('id')->all()));

        return $user;
    }

    // ------------------------------------------------------------------ the matrix

    public function test_the_roles_and_what_each_holds(): void
    {
        $this->assertEqualsCanonicalizing(['admin', 'manager', 'sales', 'marketing_manager', 'sales_manager', 'project_manager', 'finance', 'client_viewer', 'broker_viewer'], Role::tenantRoles());

        $this->assertCount(count(Permissions::all()), Permissions::forRole(Role::Admin));
        $this->assertContains('campaigns.approve', Permissions::forRole(Role::MarketingManager));
        $this->assertNotContains('campaigns.view', Permissions::forRole(Role::SalesManager));
        $this->assertNotContains('leads.create', Permissions::forRole(Role::Finance));
        $this->assertNotContains('contacts.view', Permissions::forRole(Role::ClientViewer));
        $this->assertSame([], array_filter(Permissions::forRole(Role::BrokerViewer), fn ($p) => str_starts_with($p, 'spend.') || str_starts_with($p, 'revenue.')));

        // No role holds an action the table does not define.
        foreach (Role::cases() as $role) {
            foreach (Permissions::forRole($role) as $permission) {
                $this->assertContains($permission, Permissions::all(), "{$role->value} holds an unknown {$permission}");
            }
        }
    }

    public function test_the_interface_is_told_what_the_person_may_do_and_which_data_is_hidden(): void
    {
        $finance = $this->user(Role::Finance);
        $me = $this->actingAs($finance)->getJson('/api/v1/auth/me')->assertOk()->json('data');

        $this->assertContains('reservations.export', $me['permissions']);
        $this->assertNotContains('leads.edit', $me['permissions']);
        $this->assertSame(['contacts'], $me['hidden_data']);
        $this->assertNull($me['project_scope']);

        $pm = $this->user(Role::ProjectManager, [$this->a]);
        $this->assertSame([$this->a->uuid], $this->actingAs($pm)->getJson('/api/v1/auth/me')->json('data.project_scope'));

        $this->assertSame([], $this->actingAs($this->t['admin'])->getJson('/api/v1/auth/me')->json('data.hidden_data'));
    }

    public function test_an_unclaimed_path_is_in_no_area_so_the_specialised_roles_are_refused_it(): void
    {
        $this->assertNull(RoutePermission::for(Request::create('/api/v1/something-new')));
        $this->assertSame('', RoutePermission::for(Request::create('/api/v1/auth/me')));
        $this->assertSame('campaigns.view', RoutePermission::for(Request::create('/api/v1/campaigns', 'GET')));
        $this->assertSame('campaigns.approve', RoutePermission::for(Request::create('/api/v1/action-proposals/'.str()->uuid().'/approve', 'POST')));
        $this->assertSame('leads.create', RoutePermission::for(Request::create('/api/v1/leads', 'POST')));
        $this->assertSame('leads.execute', RoutePermission::for(Request::create('/api/v1/leads/'.str()->uuid().'/notes', 'POST')));
        $this->assertSame('leads.disable', RoutePermission::for(Request::create('/api/v1/leads/'.str()->uuid(), 'DELETE')));
        $this->assertSame('analytics.export', RoutePermission::for(Request::create('/api/v1/reports/export', 'GET')));
        $this->assertSame('automation.edit', RoutePermission::for(Request::create('/api/v1/projects/'.str()->uuid().'/thresholds', 'PATCH')));
    }

    // ------------------------------------------------------------- the API refuses

    public function test_each_role_gets_its_areas_and_a_403_on_the_rest(): void
    {
        $cases = [
            [Role::Finance, [['GET', '/leads', 200], ['GET', '/campaigns', 200], ['GET', '/dashboard', 200], ['POST', '/leads', 403], ['PATCH', '/settings', 403], ['GET', '/users', 403], ['GET', '/integrations', 403], ['GET', '/automation/rules', 403], ['GET', '/tracking/links', 403], ['GET', '/report-shares', 403]]],
            [Role::MarketingManager, [['GET', '/campaigns', 200], ['GET', '/tracking/links', 200], ['GET', '/report-shares', 200], ['GET', '/leads', 200], ['POST', '/leads', 403], ['GET', '/users', 403], ['GET', '/work/next', 403], ['POST', '/work/outcome', 403], ['GET', '/appointments', 403]]],
            [Role::SalesManager, [['GET', '/leads', 200], ['GET', '/users', 200], ['GET', '/appointments', 200], ['GET', '/reservations', 200], ['GET', '/campaigns', 403], ['GET', '/tracking/links', 403], ['GET', '/report-shares', 403], ['POST', '/users', 403], ['GET', '/integrations', 403]]],
            [Role::ClientViewer, [['GET', '/leads', 200], ['GET', '/appointments', 200], ['GET', '/dashboard', 200], ['GET', '/campaigns', 403], ['POST', '/leads', 403], ['POST', '/work/outcome', 403], ['GET', '/users', 403], ['GET', '/settings', 403]]],
            [Role::BrokerViewer, [['GET', '/leads', 200], ['GET', '/reservations', 200], ['GET', '/reports', 403], ['GET', '/campaigns', 403], ['GET', '/follow-ups', 200]]],
            [Role::ProjectManager, [['GET', '/leads', 200], ['GET', '/properties', 200], ['GET', '/campaigns', 200], ['GET', '/companies', 403], ['POST', '/projects', 403], ['GET', '/users', 403], ['GET', '/integrations', 403]]],
        ];

        foreach ($cases as [$role, $calls]) {
            $user = $this->user($role, [$this->a, $this->b]);

            foreach ($calls as [$method, $path, $expected]) {
                $response = $this->actingAs($user)->json($method, "/api/v1{$path}", $method === 'GET' ? [] : ['full_name' => 'x', 'name' => 'x']);

                $this->assertSame($expected, $response->status(), "{$role->value} {$method} {$path}: ".substr($response->getContent(), 0, 160));
            }
        }
    }

    public function test_a_sales_manager_who_is_granted_a_lead_edit_is_not_refused_by_a_policy_that_never_heard_of_the_role(): void
    {
        $manager = $this->user(Role::SalesManager);

        $this->actingAs($manager)->patchJson("/api/v1/leads/{$this->inA->uuid}", ['full_name' => 'اسم جديد'])->assertOk();
        $this->actingAs($manager)->deleteJson("/api/v1/leads/{$this->inA->uuid}")->assertForbidden();   // leads.disable is not in the table for them
    }

    public function test_the_long_standing_roles_are_unchanged(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->actingAs($rep)->getJson('/api/v1/campaigns')->assertForbidden();   // a policy, as ever
        $this->actingAs($this->t['admin'])->getJson('/api/v1/campaigns')->assertOk();
        $this->actingAs($this->t['admin'])->getJson('/api/v1/leads')->assertOk()->assertJsonPath('data.0.phone', fn ($v) => $v !== null);
    }

    // ---------------------------------------------------------------- project scope

    public function test_a_scoped_role_sees_only_its_projects_everywhere(): void
    {
        $pm = $this->user(Role::ProjectManager, [$this->a]);

        $ids = fn (string $url) => collect($this->actingAs($pm)->getJson("/api/v1{$url}")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$this->inA->uuid], $ids('/leads'));
        $this->actingAs($pm)->getJson("/api/v1/leads/{$this->inA->uuid}")->assertOk();
        $this->actingAs($pm)->getJson("/api/v1/leads/{$this->inB->uuid}")->assertNotFound();

        // Counts and searches too: there is no query that can forget the scope.
        $search = $this->actingAs($pm)->getJson('/api/v1/search?q='.urlencode('عميل'))->assertOk()->json();
        $this->assertStringContainsString('النرجس', json_encode($search, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('الياسمين', json_encode($search, JSON_UNESCAPED_UNICODE));

        // The project list itself.
        $names = collect($this->actingAs($pm)->getJson('/api/v1/projects')->assertOk()->json('data.projects'))->pluck('name')->all();
        $this->assertSame(['النرجس'], $names);
    }

    public function test_a_scoped_role_with_no_project_sees_nothing_not_everything(): void
    {
        $client = $this->user(Role::ClientViewer, []);

        $this->assertSame([], $this->actingAs($client)->getJson('/api/v1/leads')->assertOk()->json('data'));
        $this->actingAs($client)->getJson("/api/v1/leads/{$this->inA->uuid}")->assertNotFound();
    }

    public function test_campaigns_and_reservations_follow_the_scope(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            Campaign::create(['platform' => 'meta', 'name' => 'حملة النرجس', 'origin' => 'manual', 'project_id' => $this->a->id]);
            Campaign::create(['platform' => 'meta', 'name' => 'حملة الياسمين', 'origin' => 'manual', 'project_id' => $this->b->id]);
            Reservation::create(['lead_id' => $this->inA->id, 'status' => 'pending']);
            Reservation::create(['lead_id' => $this->inB->id, 'status' => 'pending']);
        });

        $pm = $this->user(Role::ProjectManager, [$this->a]);

        $campaigns = collect($this->actingAs($pm)->getJson('/api/v1/campaigns')->assertOk()->json('data.campaigns'))->pluck('name')->all();
        $this->assertSame(['حملة النرجس'], $campaigns);

        $this->assertCount(1, $this->actingAs($pm)->getJson('/api/v1/reservations')->assertOk()->json('data'));
        $this->assertCount(2, $this->actingAs($this->t['admin'])->getJson('/api/v1/reservations')->assertOk()->json('data'));
    }

    // -------------------------------------------------------------------- fields

    public function test_contacts_never_reach_a_role_without_them_whichever_endpoint_it_asks(): void
    {
        foreach ([Role::Finance, Role::ClientViewer, Role::BrokerViewer, Role::MarketingManager] as $role) {
            $user = $this->user($role, [$this->a, $this->b]);

            foreach (['/leads', "/leads/{$this->inA->uuid}"] as $path) {
                $body = json_encode($this->actingAs($user)->getJson("/api/v1{$path}")->assertOk()->json(), JSON_UNESCAPED_UNICODE);

                $this->assertStringNotContainsString('0551234567', $body, "{$role->value} {$path} leaked a phone");
                $this->assertStringNotContainsString('+96655', $body, "{$role->value} {$path} leaked a normalised phone");
                $this->assertStringNotContainsString('@example.test', $body, "{$role->value} {$path} leaked an email");
                $this->assertStringContainsString('عميل', $body, 'the lead itself is still there');
            }
        }

        // The roles who work leads keep them.
        foreach ([Role::SalesManager, Role::ProjectManager] as $role) {
            $this->assertStringContainsString('0551234567', json_encode($this->actingAs($this->user($role, [$this->a]))->getJson("/api/v1/leads/{$this->inA->uuid}")->json(), JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_money_is_scrubbed_by_kind(): void
    {
        $this->asTenant($this->t['tenant'], fn () => MetricSnapshot::create(['platform' => 'meta', 'level' => 'campaign', 'campaign_id' => Campaign::create(['platform' => 'meta', 'name' => 'ح', 'origin' => 'manual', 'project_id' => $this->a->id])->id, 'date' => now()->toDateString(), 'spend_minor' => 100_000, 'currency' => 'SAR']));

        // Finance sees the money.
        $finance = $this->actingAs($this->user(Role::Finance))->getJson('/api/v1/campaigns')->assertOk()->json('data.campaigns.0');
        $this->assertArrayHasKey('spend', $finance);
        $this->assertArrayHasKey('revenue', $finance);

        // Sales manager: revenue yes, what the ads cost no — and the same lead's deal value for a client: neither.
        $this->assertStringNotContainsString('deal_value', $this->actingAs($this->user(Role::ClientViewer, [$this->a]))->getJson("/api/v1/leads/{$this->inA->uuid}")->getContent());
        $this->assertStringContainsString('deal_value', $this->actingAs($this->user(Role::SalesManager))->getJson("/api/v1/leads/{$this->inA->uuid}")->getContent());
    }

    // ----------------------------------------------------------- managing people

    public function test_an_admin_gives_a_role_and_projects_and_it_is_on_the_record(): void
    {
        $created = $this->actingAs($this->t['admin'])->postJson('/api/v1/users', [
            'name' => 'مدير المشروع', 'email' => 'pm@example.test', 'password' => 'password123', 'role' => 'project_manager', 'project_ids' => [$this->a->uuid],
        ])->assertCreated()->json('data.user');

        $this->assertSame('project_manager', $created['role']);
        $this->assertSame([$this->a->uuid], $created['project_ids']);

        $this->actingAs($this->t['admin'])->patchJson("/api/v1/users/{$created['id']}", ['project_ids' => [$this->a->uuid, $this->b->uuid]])->assertOk()->assertJsonPath('data.user.project_ids', [$this->a->uuid, $this->b->uuid]);

        $trail = $this->asTenant($this->t['tenant'], fn () => AuditLog::where('action', 'user.projects_changed')->count());
        $this->assertSame(2, $trail);

        // Only the workspace's own projects can be given.
        $other = $this->provisionTenant('Other Co');
        $foreign = $this->asTenant($other['tenant'], fn () => Project::create(['name' => 'خارجي']));
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/users/{$created['id']}", ['project_ids' => [$foreign->uuid]])->assertOk()->assertJsonPath('data.user.project_ids', []);

        // And only an admin does it.
        $manager = $this->makeUser($this->t['tenant'], Role::Manager);
        $this->actingAs($manager)->patchJson("/api/v1/users/{$created['id']}", ['project_ids' => []])->assertForbidden();
        $this->actingAs($this->t['admin'])->postJson('/api/v1/users', ['name' => 'x', 'email' => 'bad@example.test', 'password' => 'password123', 'role' => 'overlord'])->assertStatus(422);
    }

    public function test_the_roles_screen_reads_the_same_matrix_the_api_enforces(): void
    {
        $roles = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/roles')->assertOk()->json('data.roles'))->keyBy('role');

        $this->assertSame(Permissions::forRole(Role::Finance), collect($roles['finance']['grants'])->flatMap(fn ($actions, $resource) => collect($actions)->map(fn ($a) => "{$resource}.{$a}"))->values()->all());
        $this->assertTrue($roles['client_viewer']['scoped']);
        $this->assertTrue($roles['broker_viewer']['external']);
        $this->assertFalse($roles['finance']['scoped']);

        $this->actingAs($this->makeUser($this->t['tenant'], Role::Sales))->getJson('/api/v1/roles')->assertForbidden();
    }

    public function test_a_person_may_always_update_their_own_profile(): void
    {
        $finance = $this->user(Role::Finance);

        $r = $this->actingAs($finance)->patchJson("/api/v1/users/{$finance->uuid}", ['away_until' => now()->addDays(3)->toDateString()]);
        $this->assertSame(200, $r->status(), $r->getContent());
        $other = $this->user(Role::Finance);
        $this->actingAs($finance)->patchJson("/api/v1/users/{$other->uuid}", ['name' => 'x'])->assertForbidden();
    }
}
