<?php

namespace Tests\Feature;

use App\Domain\Access\AccessResolver;
use App\Domain\Access\Permissions;
use App\Domain\Reports\ReportPresets;
use App\Domain\Reports\ReportTemplates;
use App\Enums\Role;
use App\Models\AutomationActionLog;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use App\Models\Partner;
use App\Models\PermissionRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The reports a workspace starts from, and the management report that carries marketing, sales,
 * automation and tracking in one place — with who may add which page, what a partner never sees and
 * what the public page leaves out.
 */
class ReportPresetsAndExecutiveReportTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Preset Co');
    }

    private function create(array $data, $as = null)
    {
        return $this->actingAs($as ?? $this->t['admin'])->postJson('/api/v1/report-shares', $data);
    }

    private function open(array $share)
    {
        return $this->getJson('/api/v1/shared/reports/'.basename($share['url']));
    }

    private function seedMonth(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->asTenant($this->t['tenant'], function () use ($rep) {
            $campaign = Campaign::create(['platform' => 'meta', 'name' => 'حملة', 'origin' => 'manual', 'status' => 'active', 'currency' => 'SAR']);
            MetricSnapshot::create(['platform' => 'meta', 'level' => 'campaign', 'campaign_id' => $campaign->id, 'date' => now()->subDays(3)->toDateString(), 'spend_minor' => 100_000, 'currency' => 'SAR']);

            foreach (range(1, 6) as $i) {
                $lead = Lead::create(['full_name' => "عميل {$i}", 'phone_normalized' => '+9665'.random_int(10000000, 99999999), 'status' => $i <= 2 ? 'won' : 'contacted', 'assigned_to' => $rep->id, 'first_contact_at' => now(), 'last_contact_at' => now(), 'deal_value_minor' => $i <= 2 ? 50_000_000 : null, 'won_at' => $i <= 2 ? now() : null]);
                DB::table('leads')->where('id', $lead->id)->update(['campaign_ref_id' => $campaign->id, 'assigned_at' => now()->subHours(2)->toDateTimeString(), 'created_at' => now()->subDays(2)]);
            }

            AutomationActionLog::create(['lead_id' => Lead::first()->id, 'trigger' => 'lead.created', 'action_type' => 'send_whatsapp', 'result' => AutomationActionLog::COMPLETED, 'happened_at' => now()->subDay()]);
            AutomationActionLog::create(['lead_id' => Lead::first()->id, 'trigger' => 'lead.created', 'action_type' => 'send_whatsapp', 'result' => AutomationActionLog::FAILED, 'happened_at' => now()->subDay()]);
        });
    }

    // ------------------------------------------------------------ presets

    public function test_six_presets_come_ready_each_with_its_reader_its_period_and_its_sections(): void
    {
        $this->assertSame(['executive_management', 'marketing', 'sales', 'client', 'investor', 'partner'], array_keys(ReportPresets::PRESETS));

        $directory = $this->actingAs($this->t['admin'])->getJson('/api/v1/report-shares')->assertOk()->json('data');
        $presets = collect($directory['presets'])->keyBy('key');

        $this->assertCount(6, $presets);
        foreach ($presets as $preset) {
            $this->assertArrayHasKey($preset['template'], ReportTemplates::TEMPLATES, "{$preset['key']} sits on a template");
            $this->assertNotEmpty(array_filter($preset['sections']), "{$preset['key']} carries sections");
        }

        $management = $presets['executive_management'];
        $this->assertSame(30, $management['days']);
        $this->assertTrue($management['compare']);
        foreach (['exec_marketing', 'exec_sales', 'exec_automation', 'exec_tracking'] as $page) {
            $this->assertTrue($management['sections'][$page], "the management report has {$page}");
        }
        $this->assertSame(90, $presets['investor']['days']);
        $this->assertFalse($presets['client']['compare']);
        $this->assertTrue($presets['partner']['needs_partner']);
        $this->assertSame(['exec_marketing', 'exec_sales'], array_values(array_intersect(['exec_marketing', 'exec_sales'], $directory['money'])), 'the two pages with money are money sections');
    }

    public function test_a_preset_fills_in_what_the_form_leaves_out_and_the_form_wins_over_it(): void
    {
        $share = $this->create(['name' => 'للإدارة', 'preset' => 'executive_management'])->assertCreated()->json('data.share');
        $this->assertSame('executive_management', $share['template']);
        $this->assertSame(30, $share['days']);
        $this->assertTrue($share['compare']);
        $this->assertSame(300, $share['refresh_seconds']);

        $investor = $this->create(['name' => 'للمستثمر', 'preset' => 'investor'])->assertCreated()->json('data.share');
        $this->assertSame(90, $investor['days']);

        $own = $this->create(['name' => 'مخصص', 'preset' => 'investor', 'days' => 7, 'compare' => false, 'template' => 'client'])->assertCreated()->json('data.share');
        $this->assertSame('client', $own['template']);
        $this->assertSame(7, $own['days']);
        $this->assertFalse($own['compare']);

        $this->create(['name' => 'x'])->assertStatus(422);
        $this->create(['name' => 'x', 'preset' => 'nonsense'])->assertStatus(422);
        $this->create(['name' => 'شريك', 'preset' => 'partner'])->assertStatus(422);

        $partner = $this->asTenant($this->t['tenant'], fn () => Partner::create(['name' => 'وسيط', 'type' => 'broker']));
        $this->create(['name' => 'شريك', 'preset' => 'partner', 'partner_id' => $partner->uuid])->assertCreated();
    }

    // ------------------------------------------------------------ the management report

    public function test_the_management_report_carries_the_four_pages_with_every_figure_asked_for(): void
    {
        $this->seedMonth();
        $share = $this->create(['name' => 'للإدارة', 'preset' => 'executive_management'])->assertCreated()->json('data.share');

        $report = $this->open($share)->assertOk()->json('data');

        $this->assertSame(['exec_marketing', 'exec_sales', 'funnel', 'exec_automation', 'exec_tracking', 'trend', 'insights', 'recommendations'], $report['sections']);

        // Marketing: spend, leads, CPL, qualified, CPQL, interested, visits, reservations, CAC, ROAS.
        $marketing = $report['exec_marketing'];
        $this->assertEqualsCanonicalizing(['spend', 'leads', 'cpl', 'qualified', 'cpql', 'interested', 'visits', 'reservations', 'cac', 'roas'], array_keys($marketing['figures']));
        $this->assertSame(6, $marketing['figures']['leads']);
        $this->assertEquals(1000.0, $marketing['figures']['spend']);
        $this->assertEqualsCanonicalizing(array_keys($marketing['figures']), array_keys($marketing['previous']), 'the period before, beside every figure');

        // Sales: assigned, contacted, response time, follow-ups, visits, reservations, won, revenue, close rate, SLA.
        $sales = $report['exec_sales'];
        $this->assertEqualsCanonicalizing(['assigned', 'contacted', 'response_minutes', 'follow_up_completion', 'visits', 'reservations', 'won', 'revenue', 'close_rate', 'sla'], array_keys($sales['figures']));
        $this->assertSame(6, $sales['figures']['assigned']);
        $this->assertSame(6, $sales['figures']['contacted']);
        $this->assertSame(2, $sales['figures']['won']);
        $this->assertEquals(1_000_000.0, $sales['figures']['revenue']);
        $this->assertEquals(33.3, $sales['figures']['close_rate']);
        $this->assertNotNull($sales['previous']);

        // Automation: coverage, success, failure, needs human.
        $automation = $report['exec_automation'];
        $this->assertEqualsCanonicalizing(['coverage', 'success', 'failures', 'needs_human'], array_keys($automation['figures']));
        $this->assertEquals(50.0, $automation['figures']['success']);
        $this->assertSame(1, $automation['figures']['failures']);

        // Tracking: attribution, UTM health, unattributed, offline conversions.
        $tracking = $report['exec_tracking'];
        $this->assertEqualsCanonicalizing(['attribution', 'utm_health', 'unattributed', 'offline_sent', 'offline_failed', 'offline_success'], array_keys($tracking['figures']));
        $this->assertSame(0, $tracking['figures']['offline_sent']);
        $this->assertNull($tracking['figures']['offline_success'], 'nothing sent is a dash, not a zero');
    }

    public function test_without_compare_there_is_no_previous_period(): void
    {
        $share = $this->create(['name' => 'بلا مقارنة', 'preset' => 'executive_management', 'compare' => false])->assertCreated()->json('data.share');

        $report = $this->open($share)->assertOk()->json('data');

        foreach (['exec_marketing', 'exec_sales', 'exec_automation', 'exec_tracking'] as $page) {
            $this->assertNull($report[$page]['previous'], "{$page} has no previous period");
        }
    }

    // ------------------------------------------------------------ who may, who sees

    public function test_nobody_adds_a_page_built_from_what_they_may_not_see(): void
    {
        $manager = $this->makeUser($this->t['tenant'], Role::Manager);
        $this->assertSame(201, $this->create(['name' => 'ok', 'preset' => 'executive_management'], $manager)->status(), 'a manager has all of it');

        foreach (['spend.view' => 'exec_marketing', 'revenue.view' => 'exec_sales'] as $permission => $page) {
            $this->asTenant($this->t['tenant'], fn () => PermissionRule::create(['layer' => 'role', 'role_key' => 'manager', 'permission' => $permission, 'effect' => 'deny']));
            AccessResolver::flush();

            $directory = $this->actingAs($manager->fresh())->getJson('/api/v1/report-shares')->assertOk()->json('data');
            $this->assertContains($page, $directory['unavailable'], "{$page} is not offered");

            $response = $this->create(['name' => "بلا {$permission}", 'preset' => 'executive_management'], $manager->fresh());
            $response->assertStatus(422);
            $this->assertStringContainsString($page, json_encode($response->json()));

            // Back to what it was, so the next permission is judged on its own.
            $this->asTenant($this->t['tenant'], fn () => PermissionRule::query()->delete());
            AccessResolver::flush();
        }

        // The pages the person may still see can still be added.
        $this->assertSame(201, $this->create(['name' => 'تشغيلي', 'template' => 'executive_management', 'sections' => ['exec_automation' => true, 'exec_tracking' => true]], $manager->fresh())->status());
    }

    public function test_external_roles_and_reps_cannot_make_reports_at_all(): void
    {
        foreach ([Role::Sales, Role::ClientViewer, Role::BrokerViewer, Role::Finance] as $role) {
            $this->create(['name' => 'x', 'preset' => 'executive_management'], $this->makeUser($this->t['tenant'], $role))->assertForbidden();
        }

        $other = $this->provisionTenant('Else');
        $share = $this->create(['name' => 'ours', 'preset' => 'client'])->assertCreated()->json('data.share');
        $this->actingAs($other['admin'])->getJson('/api/v1/report-shares')->assertOk()->assertJsonCount(0, 'data.shares');
        $this->actingAs($other['admin'])->postJson("/api/v1/report-shares/{$share['id']}/revoke")->assertNotFound();
    }

    public function test_a_page_kept_for_the_team_is_in_the_preview_and_not_on_the_public_page(): void
    {
        $share = $this->create(['name' => 'للإدارة', 'preset' => 'executive_management'])->assertCreated()->json('data.share');
        $layout = collect($share['layout'])->map(fn ($row) => $row['key'] === 'exec_sales' ? ['visible_to' => 'team'] + $row : $row)->all();
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/report-shares/{$share['id']}", ['layout' => $layout])->assertOk();

        $public = $this->open($share)->assertOk()->json('data');
        $this->assertNotContains('exec_sales', $public['sections']);
        $this->assertArrayNotHasKey('exec_sales', $public, 'not hidden by the page: not in the response');
        $this->assertContains('exec_marketing', $public['sections']);

        $preview = $this->actingAs($this->t['admin'])->getJson("/api/v1/report-shares/{$share['id']}/preview")->assertOk()->json('data');
        $this->assertContains('exec_sales', $preview['sections']);
        $this->assertArrayHasKey('exec_sales', $preview);
    }

    public function test_a_partners_report_never_carries_the_management_pages_whatever_was_switched_on(): void
    {
        $partner = $this->asTenant($this->t['tenant'], fn () => Partner::create(['name' => 'وسيط', 'type' => 'broker']));
        $share = $this->create(['name' => 'لوسيط', 'preset' => 'partner', 'partner_id' => $partner->uuid, 'sections' => ['kpi_volume' => true, 'exec_marketing' => true, 'exec_sales' => true, 'exec_automation' => true, 'exec_tracking' => true]])->assertCreated()->json('data.share');

        $this->assertFalse($share['sections']['exec_marketing']);
        $this->assertFalse($share['sections']['exec_sales']);

        $report = $this->open($share)->assertOk()->json('data');
        foreach (['exec_marketing', 'exec_sales', 'exec_automation', 'exec_tracking'] as $page) {
            $this->assertNotContains($page, $report['sections']);
            $this->assertArrayNotHasKey($page, $report);
        }
    }

    public function test_the_four_pages_are_elements_a_workspace_can_switch_off_for_a_role(): void
    {
        foreach (['exec_marketing', 'exec_sales', 'exec_automation', 'exec_tracking'] as $page) {
            $this->assertNotNull(Permissions::element("report.section.{$page}"), $page);
        }
        $this->assertContains('spend.view', Permissions::element('report.section.exec_marketing')['needs']);
        $this->assertContains('revenue.view', Permissions::element('report.section.exec_sales')['needs']);
    }
}
