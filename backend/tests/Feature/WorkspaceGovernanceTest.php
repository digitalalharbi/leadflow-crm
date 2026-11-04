<?php

namespace Tests\Feature;

use App\Domain\Access\AccessResolver;
use App\Domain\Access\Destination;
use App\Enums\Role;
use App\Models\AccessRole;
use App\Models\Lead;
use App\Models\PlatformAuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The owner's controls, and one door: what is switched off is refused by the server, and the server says where you land. */
class WorkspaceGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->provisionTenant('Alpha');
    }

    private function switchOff(array $modules)
    {
        return $this->actingAs($this->makePlatformOwner())->patchJson("/api/v1/super-admin/tenants/{$this->a['tenant']->uuid}/subscription", ['disabled_modules' => $modules]);
    }

    public function test_a_switched_off_module_is_refused_by_the_api_and_leaves_the_navigation(): void
    {
        $this->switchOff(['automation', 'reports'])->assertOk();
        $this->app['auth']->forgetGuards();
        $admin = $this->a['admin']->fresh();

        $this->actingAs($admin)->getJson('/api/v1/automation/rules')->assertForbidden()->assertJsonPath('data.code', 'module_disabled');
        $this->actingAs($admin)->getJson('/api/v1/reports/campaign-quality')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/v1/leads')->assertOk();

        $hidden = $this->actingAs($admin)->getJson('/api/v1/auth/me')->assertOk()->json('data.hidden_elements');
        $this->assertContains('page.automation', $hidden);
        $this->assertContains('page.reports', $hidden);
    }

    public function test_it_is_recorded_with_before_and_after_and_unknown_modules_are_refused(): void
    {
        $this->switchOff(['exports'])->assertOk();
        $log = PlatformAuditLog::where('action', 'subscription.updated')->latest('id')->firstOrFail();
        $this->assertSame(['exports'], $log->changes['after']['disabled_modules'] ?? null);

        $this->switchOff(['nonsense'])->assertUnprocessable();
    }

    public function test_revenue_off_takes_the_figures_out_for_everybody_in_the_company(): void
    {
        $this->switchOff(['revenue'])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->assertContains('revenue', $this->actingAs($this->a['admin']->fresh())->getJson('/api/v1/auth/me')->json('data.hidden_data'));
    }

    public function test_public_capture_keeps_working_with_everything_off(): void
    {
        $this->switchOff(['landing', 'integrations', 'webhooks'])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/public/landing/does-not-exist')->assertNotFound();
    }

    public function test_the_matrix_says_what_the_api_would_decide_and_only_to_the_platform(): void
    {
        $this->switchOff(['automation'])->assertOk();

        $rows = collect($this->getJson("/api/v1/super-admin/tenants/{$this->a['tenant']->uuid}/access-matrix")->assertOk()->json('data.rows'))->keyBy('module');
        $this->assertFalse($rows['automation']['enabled']);
        $this->assertFalse($rows['automation']['roles']['admin']);
        $this->assertTrue($rows['reports']['roles']['admin']);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->actingAs($this->a['admin'])->getJson("/api/v1/super-admin/tenants/{$this->a['tenant']->uuid}/access-matrix")->assertNotFound();
    }

    public function test_one_login_the_platform_owner_is_sent_on_and_only_with_the_right_password(): void
    {
        $owner = $this->makePlatformOwner('boss@mohtam.test');

        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password123'])->assertOk()->assertJsonPath('data.platform', true)->assertJsonMissingPath('data.token');
        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'wrong-password'])->assertUnprocessable()->assertJsonMissingPath('data.platform');
    }

    public function test_the_server_says_where_each_person_lands(): void
    {
        $this->assertSame('/dashboard', Destination::for($this->a['admin']));
        $this->assertSame('/work', Destination::for($this->makeUser($this->a['tenant'], Role::Sales)));
        $this->assertSame('/platform', Destination::for($this->makePlatformOwner('x@mohtam.test')));

        $this->postJson('/api/v1/auth/login', ['email' => $this->makeUser($this->a['tenant'], Role::Sales, ['email' => 'rep@x.test', 'password' => 'a-strong-pass'])->email, 'password' => 'a-strong-pass'])->assertOk()->assertJsonPath('data.home', '/work');
    }

    public function test_looking_at_one_project_narrows_and_never_widens(): void
    {
        [$mine, $other] = $this->asTenant($this->a['tenant'], fn () => [Project::create(['name' => 'Mine']), Project::create(['name' => 'Other'])]);
        $this->asTenant($this->a['tenant'], function () use ($mine, $other) {
            Lead::create(['full_name' => 'In mine', 'phone' => '0551111111', 'project_id' => $mine->id]);
            Lead::create(['full_name' => 'In other', 'phone' => '0552222222', 'project_id' => $other->id]);
        });
        $names = fn (User $user, Project $project) => collect($this->actingAs($user)->withHeader('X-Project-Id', $project->uuid)->getJson('/api/v1/leads')->assertOk()->json('data'))->pluck('full_name')->all();

        $this->assertSame(['In mine'], $names($this->a['admin'], $mine));

        $limited = $this->makeUser($this->a['tenant'], Role::Manager);
        $limited->forceFill(['all_projects' => false])->save();
        $limited->projects()->sync([$mine->id]);
        $this->assertSame(['In mine'], $names($limited, $other), 'Naming a project one was not given changes nothing.');
    }

    public function test_one_person_can_lose_a_module_from_the_console_and_the_api_refuses_them_alone(): void
    {
        $tenant = $this->a['tenant'];
        $rep = $this->makeUser($tenant, Role::Sales);
        $other = $this->makeUser($tenant, Role::Sales);
        $url = "/api/v1/super-admin/tenants/{$tenant->uuid}/users/{$rep->uuid}/access";

        $this->actingAs($this->makePlatformOwner())->patchJson($url, ['module' => 'visits', 'allowed' => false])->assertOk();
        $log = PlatformAuditLog::where('action', 'user.access_changed')->latest('id')->firstOrFail();
        $this->assertSame('visits', $log->changes['module'] ?? null);

        $this->app['auth']->forgetGuards();
        AccessResolver::flush();
        $this->actingAs($rep->fresh())->getJson('/api/v1/appointments')->assertForbidden();
        $this->app['auth']->forgetGuards();
        AccessResolver::flush();
        $this->actingAs($other->fresh())->getJson('/api/v1/appointments')->assertOk();

        $this->app['auth']->forgetGuards();
        AccessResolver::flush();
        $this->actingAs($this->makePlatformOwner('second@platform.test'))->patchJson($url, ['module' => 'visits', 'allowed' => true])->assertOk();
        $this->app['auth']->forgetGuards();
        AccessResolver::flush();
        $this->actingAs($rep->fresh())->getJson('/api/v1/appointments')->assertOk();
    }

    public function test_an_admin_cannot_be_narrowed_and_a_company_admin_cannot_reach_the_console_route(): void
    {
        $tenant = $this->a['tenant'];
        $url = "/api/v1/super-admin/tenants/{$tenant->uuid}/users/{$this->a['admin']->uuid}/access";

        $this->actingAs($this->makePlatformOwner())->patchJson($url, ['module' => 'visits', 'allowed' => false])->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        AccessResolver::flush();
        $this->actingAs($this->a['admin'])->patchJson($url, ['module' => 'visits', 'allowed' => false])->assertNotFound();
    }

    public function test_a_module_that_borrows_another_screen_s_permission_cannot_be_taken_from_one_person(): void
    {
        $rep = $this->makeUser($this->a['tenant'], Role::Sales);
        $url = "/api/v1/super-admin/tenants/{$this->a['tenant']->uuid}/users/{$rep->uuid}/access";

        // Exports reads with leads.view: refusing it per person would have taken the whole lead list.
        $this->actingAs($this->makePlatformOwner())->patchJson($url, ['module' => 'exports', 'allowed' => false])->assertUnprocessable();
        $this->assertNotContains('exports', array_column($this->getJson($url)->assertOk()->json('data.modules'), 'module'));
    }

    public function test_a_person_of_another_company_is_not_found_through_this_one(): void
    {
        $other = $this->provisionTenant('Beta');
        $url = "/api/v1/super-admin/tenants/{$this->a['tenant']->uuid}/users/{$other['admin']->uuid}/access";

        $this->actingAs($this->makePlatformOwner())->getJson($url)->assertNotFound();
    }

    public function test_the_matrix_includes_this_company_s_own_roles_only(): void
    {
        $other = $this->provisionTenant('Beta');
        $grants = ['leads' => ['view']];
        AccessRole::withoutGlobalScopes()->forceCreate(['tenant_id' => $this->a['tenant']->id, 'key' => 'site_agent', 'name' => 'Site agent', 'base_role' => 'sales', 'grants' => $grants]);
        AccessRole::withoutGlobalScopes()->forceCreate(['tenant_id' => $other['tenant']->id, 'key' => 'theirs', 'name' => 'Theirs', 'base_role' => 'sales', 'grants' => $grants]);

        $data = $this->actingAs($this->makePlatformOwner())->getJson("/api/v1/super-admin/tenants/{$this->a['tenant']->uuid}/access-matrix")->assertOk()->json('data');

        $this->assertContains('site_agent', $data['roles']);
        $this->assertNotContains('theirs', $data['roles']);
    }
}
