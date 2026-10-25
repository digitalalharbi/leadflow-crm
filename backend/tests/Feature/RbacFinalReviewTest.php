<?php

namespace Tests\Feature;

use App\Domain\Access\Permissions;
use App\Enums\Role;
use App\Models\Anomaly;
use App\Models\Campaign;
use App\Models\PermissionRule;
use App\Models\Project;
use App\Models\ReportShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The last look at access before the batch closes: the doors added lately (the operations table,
 * self-approve, the delivery log, a report emailed, a team preview) obey the same four things as
 * every older one — hidden elements, scrubbed fields, project scope, and what an external role may
 * never reach.
 */
class RbacFinalReviewTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Review Co');
    }

    private function rule(string $layer, string $permission, string $effect, array $scope = []): void
    {
        $this->asTenant($this->t['tenant'], fn () => PermissionRule::create($scope + ['layer' => $layer, 'permission' => $permission, 'effect' => $effect]));
    }

    private function share(string $template = 'marketing'): ReportShare
    {
        $id = $this->actingAs($this->t['admin'])->postJson('/api/v1/report-shares', ['name' => 'تقرير', 'template' => $template])->assertCreated()->json('data.share.id');

        return ReportShare::withoutGlobalScopes()->where('uuid', $id)->firstOrFail();
    }

    public function test_a_team_preview_withholds_the_sections_the_viewer_may_not_see(): void
    {
        $share = $this->share('marketing');
        $manager = $this->makeUser($this->t['tenant'], Role::Manager);

        $this->assertContains('kpi_cost', $this->actingAs($manager)->getJson("/api/v1/report-shares/{$share->uuid}/preview")->assertOk()->json('data.sections'));

        // The workspace takes spend away from managers: the cost section is gone from their preview, and named as withheld.
        $this->rule('role', 'spend.view', 'deny', ['role_key' => 'sales_manager']);
        $this->rule('role', 'spend.view', 'deny', ['role_key' => 'manager']);
        $preview = $this->actingAs($manager->fresh())->getJson("/api/v1/report-shares/{$share->uuid}/preview")->assertOk()->json('data');

        $this->assertNotContains('kpi_cost', $preview['sections']);
        $this->assertContains('funnel', $preview['sections']);
        $this->assertSame(['kpi_cost', 'marketing'], $preview['withheld']);
        $this->assertStringNotContainsString('"cost"', json_encode($preview['kpis'] ?? []), 'nothing of the cost section was built');

        // The admin's preview is whole, and the share itself is unchanged.
        $this->assertContains('kpi_cost', $this->actingAs($this->t['admin'])->getJson("/api/v1/report-shares/{$share->uuid}/preview")->json('data.sections'));
        $this->assertTrue($share->fresh()->sections['kpi_cost']);
    }

    public function test_an_external_role_reaches_none_of_the_new_doors(): void
    {
        $share = $this->share('client');
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النخيل']));
        $this->asTenant($this->t['tenant'], function () use ($project) {
            Anomaly::create(['kind' => 'lead_drop', 'subject' => '', 'detected_on' => today()->toDateString(), 'severity' => 'warning', 'observed' => 2, 'expected' => 10, 'context' => []]);
            Anomaly::create(['kind' => 'lead_drop', 'subject' => 'النخيل', 'detected_on' => today()->toDateString(), 'severity' => 'warning', 'observed' => 1, 'expected' => 8, 'context' => [], 'project_id' => $project->id]);
        });

        foreach ([Role::ClientViewer, Role::BrokerViewer] as $role) {
            $viewer = $this->makeUser($this->t['tenant'], $role);
            $this->actingAs($this->t['admin'])->patchJson("/api/v1/users/{$viewer->uuid}", ['project_ids' => [$project->uuid]])->assertOk();
            $viewer = $viewer->fresh();

            $this->actingAs($viewer)->getJson('/api/v1/email-deliveries')->assertForbidden();
            $this->actingAs($viewer)->postJson("/api/v1/report-shares/{$share->uuid}/email", ['recipients' => ['a@example.test']])->assertForbidden();
            $this->actingAs($viewer)->getJson("/api/v1/report-shares/{$share->uuid}/preview")->assertForbidden();
            $this->actingAs($viewer)->getJson('/api/v1/settings/self-approval')->assertForbidden();
            $this->actingAs($viewer)->patchJson('/api/v1/settings/self-approval', ['enabled' => true])->assertForbidden();
            $this->actingAs($viewer)->getJson('/api/v1/campaigns/operations')->assertForbidden();
            $this->actingAs($viewer)->getJson('/api/v1/anomaly-settings')->assertForbidden();
            // Where their window opens at all (a client reads analytics, a broker does not), the workspace's findings are not in it.
            $insights = $this->actingAs($viewer)->getJson('/api/v1/insights');
            $insights->getStatusCode() === 403 ? $insights->assertForbidden() : $this->assertSame([], $insights->assertOk()->json('data.anomalies'));
            $this->actingAs($viewer)->getJson('/api/v1/access')->assertForbidden();
            $this->assertTrue($viewer->isExternal());
        }

        // A project manager inside the company sees what was found in their project, never the workspace figure.
        $pm = $this->makeUser($this->t['tenant'], Role::ProjectManager);
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/users/{$pm->uuid}", ['project_ids' => [$project->uuid]])->assertOk();
        $this->assertSame(['النخيل'], array_column($this->actingAs($pm->fresh())->getJson('/api/v1/insights')->assertOk()->json('data.anomalies'), 'subject'));
        $this->assertCount(2, $this->actingAs($this->t['admin'])->getJson('/api/v1/insights')->json('data.anomalies'));
    }

    public function test_the_operations_table_follows_the_project_scope(): void
    {
        [$a, $b] = $this->asTenant($this->t['tenant'], fn () => [Project::create(['name' => 'أ']), Project::create(['name' => 'ب'])]);
        $this->asTenant($this->t['tenant'], function () use ($a, $b) {
            Campaign::create(['platform' => 'meta', 'external_id' => 'a1', 'name' => 'حملة أ', 'origin' => 'sync', 'status' => 'active', 'project_id' => $a->id, 'daily_budget_minor' => 10000]);
            Campaign::create(['platform' => 'meta', 'external_id' => 'b1', 'name' => 'حملة ب', 'origin' => 'sync', 'status' => 'active', 'project_id' => $b->id, 'daily_budget_minor' => 10000]);
        });

        $pm = $this->makeUser($this->t['tenant'], Role::ProjectManager);
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/users/{$pm->uuid}", ['project_ids' => [$a->uuid]])->assertOk();

        $names = collect($this->actingAs($pm->fresh())->getJson('/api/v1/campaigns/operations')->assertOk()->json('data.campaigns'))->pluck('name')->all();
        $this->assertSame(['حملة أ'], $names);

        // No project given: nothing, not everything.
        $nobody = $this->makeUser($this->t['tenant'], Role::ProjectManager);
        $this->assertSame([], $this->actingAs($nobody)->getJson('/api/v1/campaigns/operations')->assertOk()->json('data.campaigns'));
    }

    public function test_an_element_switched_off_is_scrubbed_from_the_operations_table_and_a_project_rule_applies_only_there(): void
    {
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'المرجان']));
        $this->asTenant($this->t['tenant'], fn () => Campaign::create(['platform' => 'meta', 'external_id' => 'm1', 'name' => 'حملة', 'origin' => 'sync', 'status' => 'active', 'daily_budget_minor' => 50000, 'project_id' => $project->id]));
        $marketer = $this->makeUser($this->t['tenant'], Role::MarketingManager);

        $this->assertStringContainsString('daily_budget', json_encode($this->actingAs($marketer)->getJson('/api/v1/campaigns/operations')->assertOk()->json()));

        $this->actingAs($this->t['admin'])->patchJson('/api/v1/access/roles/marketing_manager', ['elements' => [['key' => 'campaigns.field.budget', 'state' => 'hide']]])->assertOk();
        $after = $this->actingAs($marketer->fresh())->getJson('/api/v1/campaigns/operations')->assertOk()->json();
        $this->assertStringNotContainsString('daily_budget', json_encode($after));
        $this->assertStringContainsString('"instant"', json_encode($after), 'what is not money is still there');

        // A project-layer switch is the project's alone.
        $this->actingAs($this->t['admin'])->putJson('/api/v1/access/rules', ['layer' => 'project', 'project_id' => $project->uuid, 'rules' => [['permission' => 'ui:dashboard.kpi.response', 'effect' => 'deny']]])->assertOk();
        $this->assertTrue(Permissions::sees($marketer->fresh(), 'dashboard.kpi.response'));
        $this->assertFalse(Permissions::sees($marketer->fresh(), 'dashboard.kpi.response', $project->id));
        $this->assertContains('dashboard.kpi.response', $this->actingAs($this->t['admin'])->getJson("/api/v1/access/users/{$marketer->uuid}?project_id={$project->uuid}")->json('data.hidden_elements'));
        $this->assertNotContains('dashboard.kpi.response', $this->actingAs($this->t['admin'])->getJson("/api/v1/access/users/{$marketer->uuid}")->json('data.hidden_elements'));
    }

    public function test_the_session_tells_the_interface_everything_it_gates_on(): void
    {
        $me = $this->actingAs($this->makeUser($this->t['tenant'], Role::Finance))->getJson('/api/v1/auth/me')->assertOk()->json('data');

        foreach (['permissions', 'hidden_data', 'hidden_elements', 'project_scope', 'access_role'] as $key) {
            $this->assertArrayHasKey($key, $me);
        }

        $this->assertContains('page.automation', $me['hidden_elements'], 'a page it may not open is off, so the nav never offers it');
        $this->assertContains('contacts', $me['hidden_data']);
        $this->assertNotNull(User::withoutGlobalScopes()->find($this->t['admin']->id));
    }
}
