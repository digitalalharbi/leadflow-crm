<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use App\Models\Project;
use App\Models\Reservation;
use App\Models\TrackingEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The test matrix of the reporting specification (§50): two companies with several projects each, the five roles, and the
 * shapes a workspace can be in — no ad integration, no revenue, no sales, tracking only, sales only, the full funnel,
 * several projects in one report.
 */
class ReportMatrixTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    private array $b;

    /** @var array<string,Project> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->provisionTenant('Alpha Co');
        $this->b = $this->provisionTenant('Beta Co');
        $this->putOnPlan($this->a['tenant'], 'enterprise');
        $this->putOnPlan($this->b['tenant'], 'enterprise');

        foreach (['a1' => [$this->a, 'برج ألفا الأول'], 'a2' => [$this->a, 'برج ألفا الثاني'], 'b1' => [$this->b, 'برج بيتا الأول'], 'b2' => [$this->b, 'برج بيتا الثاني']] as $key => [$t, $name]) {
            $this->p[$key] = $this->asTenant($t['tenant'], fn () => Project::create(['name' => $name]));
        }
    }

    private function lead(array $t, Project $project, string $status = 'new', array $extra = []): Lead
    {
        return $this->asTenant($t['tenant'], function () use ($project, $status, $extra) {
            $lead = new Lead;
            $lead->forceFill(array_merge([
                'full_name' => 'عميل '.Str::random(4), 'phone' => '0551234567', 'phone_normalized' => '+9665'.random_int(10000000, 99999999),
                'status' => $status, 'project_id' => $project->id, 'platform' => 'meta', 'created_at' => now()->subDays(2),
            ], $extra))->save();

            return $lead;
        });
    }

    private function report(array $t, array $sections, array $extra = []): array
    {
        $share = $this->actingAs($t['admin'])->postJson('/api/v1/report-shares', $extra + ['name' => 'تقرير', 'template' => 'client', 'sections' => array_fill_keys($sections, true)])->assertCreated()->json('data.share');

        return $this->getJson('/api/v1/public/r/'.basename($share['url']))->assertOk()->json('data');
    }

    public function test_the_five_roles_reach_exactly_what_the_permission_table_gives_them(): void
    {
        $roles = [
            'admin' => [$this->a['admin'], true, true],
            'manager' => [$this->makeUser($this->a['tenant'], Role::Manager), true, true],
            'marketing' => [$this->makeUser($this->a['tenant'], Role::MarketingManager), true, true],
            'sales_manager' => [$this->makeUser($this->a['tenant'], Role::SalesManager), false, true],
            'sales_rep' => [$this->makeUser($this->a['tenant'], Role::Sales), false, false],
        ];

        foreach ($roles as $name => [$user, $library, $funnel]) {
            $this->assertSame($library ? 200 : 403, $this->actingAs($user)->getJson('/api/v1/report-shares')->status(), "{$name}: library list");
            $this->assertSame($library ? 200 : 403, $this->actingAs($user)->getJson('/api/v1/report-links')->status(), "{$name}: client access");
            $this->assertSame($library ? 201 : 403, $this->actingAs($user)->postJson('/api/v1/report-shares', ['name' => $name, 'template' => 'client'])->status(), "{$name}: create");
            $this->assertSame($funnel ? 200 : 403, $this->actingAs($user)->getJson('/api/v1/funnel')->status(), "{$name}: funnel");
        }
    }

    public function test_a_sales_rep_sees_the_funnel_of_their_own_leads_and_not_the_companys_reach(): void
    {
        $rep = $this->makeUser($this->a['tenant'], Role::Sales);
        $this->lead($this->a, $this->p['a1'], 'contacted', ['assigned_to' => $rep->id, 'first_contact_at' => now()]);
        $this->lead($this->a, $this->p['a1']);
        $this->lead($this->a, $this->p['a1']);
        $this->asTenant($this->a['tenant'], fn () => TrackingEvent::create(['visitor_id' => 'v', 'event_type' => 'visit', 'project_id' => $this->p['a1']->id, 'occurred_at' => now()->subDay()]));

        $funnel = $this->actingAs($rep)->getJson('/api/v1/dashboard')->assertOk()->json('data.real_estate_funnel');
        $stages = collect($funnel['stages'])->keyBy('key');

        $this->assertSame(1, $stages['interest']['count']);
        $this->assertFalse($stages['awareness']['available']);
        $this->assertSame('restricted', $stages['awareness']['reason']);

        $admin = $this->actingAs($this->a['admin'])->getJson('/api/v1/dashboard')->assertOk()->json('data.real_estate_funnel');
        $this->assertSame(3, collect($admin['stages'])->firstWhere('key', 'interest')['count']);
        $this->assertTrue(collect($admin['stages'])->firstWhere('key', 'awareness')['available']);
    }

    public function test_two_companies_with_several_projects_each_never_meet(): void
    {
        foreach (['a1', 'a2'] as $k) {
            $this->lead($this->a, $this->p[$k]);
            $this->lead($this->a, $this->p[$k]);
        }
        foreach (['b1', 'b2'] as $k) {
            $this->lead($this->b, $this->p[$k], 'won', ['deal_value_minor' => 5_000_000, 'won_at' => now()->subDay()]);
        }

        $a = $this->report($this->a, ['kpi_volume', 'projects', 'revenue']);
        $b = $this->report($this->b, ['kpi_volume', 'projects', 'revenue']);

        $this->assertSame(4, $a['kpis']['leads']);
        $this->assertSame(2, $b['kpis']['leads']);
        $this->assertEqualsCanonicalizing(['برج ألفا الأول', 'برج ألفا الثاني'], array_column($a['projects'], 'name'));
        $this->assertEqualsCanonicalizing(['برج بيتا الأول', 'برج بيتا الثاني'], array_column($b['projects'], 'name'));
        $this->assertSame(0, $a['revenue']['deals']);
        $this->assertSame(2, $b['revenue']['deals']);

        // A's admin cannot name B's project in a report, nor read B's reports.
        $this->actingAs($this->a['admin'])->postJson('/api/v1/report-shares', ['name' => 'x', 'template' => 'client', 'project_ids' => [$this->p['b1']->uuid]])->assertNotFound();
    }

    public function test_a_report_over_several_projects_adds_them_and_one_project_narrows_it(): void
    {
        $this->lead($this->a, $this->p['a1']);
        $this->lead($this->a, $this->p['a2']);
        $this->lead($this->a, $this->p['a2']);

        $both = $this->report($this->a, ['kpi_volume'], ['project_ids' => [$this->p['a1']->uuid, $this->p['a2']->uuid]]);
        $one = $this->report($this->a, ['kpi_volume'], ['project_ids' => [$this->p['a2']->uuid]]);
        $portfolio = $this->report($this->a, ['kpi_volume']);

        $this->assertSame(3, $both['kpis']['leads']);
        $this->assertSame(2, $one['kpis']['leads']);
        $this->assertSame(3, $portfolio['kpis']['leads']);
        $this->assertEqualsCanonicalizing(['برج ألفا الأول', 'برج ألفا الثاني'], array_column($both['report']['projects'], 'name'));
    }

    public function test_a_tracking_only_project_has_traffic_and_no_pretended_sales(): void
    {
        $this->asTenant($this->a['tenant'], function () {
            foreach (['visit', 'visit', 'click', 'form_start'] as $type) {
                TrackingEvent::create(['visitor_id' => Str::random(6), 'event_type' => $type, 'project_id' => $this->p['a1']->id, 'occurred_at' => now()->subDay()]);
            }
        });

        $data = $this->report($this->a, ['funnel', 'tracking_hub', 'sales', 'revenue', 'marketing'], ['project_ids' => [$this->p['a1']->uuid]]);
        $stages = collect($data['funnel']['stages'])->keyBy('key');

        $this->assertTrue($stages['awareness']['available']);
        $this->assertSame('mohtam', $stages['awareness']['basis']);
        $this->assertSame(0, $stages['interest']['count']);
        $this->assertTrue($data['tracking_hub']['available']);
        $this->assertSame(2, $data['tracking_hub']['landing_views']);
        $this->assertSame(0, $data['sales']['received']);
        $this->assertNull($data['revenue']['total']);
        $this->assertFalse($data['marketing']['has_ad_data']);
    }

    public function test_a_sales_only_project_has_no_traffic_and_no_ad_numbers_and_says_so(): void
    {
        $this->lead($this->a, $this->p['a2'], 'contacted', ['platform' => null, 'source' => 'whatsapp', 'first_contact_at' => now()]);
        $this->lead($this->a, $this->p['a2'], 'negotiation', ['platform' => null, 'source' => 'whatsapp']);

        $data = $this->report($this->a, ['funnel', 'tracking_hub', 'marketing', 'campaigns', 'sources'], ['project_ids' => [$this->p['a2']->uuid]]);
        $stages = collect($data['funnel']['stages'])->keyBy('key');

        $this->assertFalse($stages['awareness']['available']);
        $this->assertNull($stages['awareness']['count']);
        $this->assertSame(2, $stages['interest']['count']);
        $this->assertFalse($data['tracking_hub']['available']);
        $this->assertFalse($data['marketing']['has_ad_data']);
        $this->assertSame([], $data['campaigns']['rows']);
        $this->assertSame(['whatsapp'], array_column($data['sources']['rows'], 'key'));
    }

    public function test_a_full_funnel_runs_from_ad_reach_to_revenue_with_each_stage_counted(): void
    {
        $campaign = $this->asTenant($this->a['tenant'], function () {
            $c = Campaign::create(['platform' => 'meta', 'name' => 'حملة كاملة', 'origin' => 'manual', 'status' => 'active', 'currency' => 'SAR', 'project_id' => $this->p['a1']->id]);
            MetricSnapshot::create(['platform' => 'meta', 'level' => 'campaign', 'campaign_id' => $c->id, 'date' => now()->subDay()->toDateString(), 'spend_minor' => 200_000, 'impressions' => 50_000, 'clicks' => 900, 'currency' => 'SAR']);

            return $c;
        });
        $m = ['campaign_ref_id' => $campaign->id, 'first_contact_at' => now()];
        $this->lead($this->a, $this->p['a1'], 'contacted', $m);
        $this->lead($this->a, $this->p['a1'], 'appointment', $m);
        $held = $this->lead($this->a, $this->p['a1'], 'negotiation', $m);
        $this->asTenant($this->a['tenant'], fn () => Reservation::create(['lead_id' => $held->id, 'project_id' => $this->p['a1']->id, 'status' => 'pending', 'amount_minor' => 100, 'reserved_at' => now()]));
        $this->lead($this->a, $this->p['a1'], 'won', $m + ['deal_value_minor' => 120_000_000, 'won_at' => now()->subDay()]);
        $this->lead($this->a, $this->p['a1'], 'new', ['campaign_ref_id' => $campaign->id, 'source' => 'referral']);

        $data = $this->report($this->a, ['funnel', 'kpi_volume', 'kpi_cost', 'kpi_revenue', 'marketing', 'revenue'], ['template' => 'management']);
        $stages = collect($data['funnel']['stages'])->keyBy('key');

        $this->assertSame(50_000, $stages['awareness']['count']);
        $this->assertSame('platform', $stages['awareness']['basis']);
        $this->assertSame(5, $stages['interest']['count']);
        $this->assertSame(4, $stages['engagement']['count']);
        $this->assertSame(3, $stages['booking']['count']);
        $this->assertSame(2, $stages['decision']['count']);
        $this->assertSame(1, $stages['referral']['count']);
        $this->assertEquals(1_200_000.0, $data['revenue']['total']);
        $this->assertEquals(2000.0, $data['kpis']['spend']);
        $this->assertEquals(600.0, $data['kpis']['roas']);
    }

    public function test_no_revenue_and_no_sales_are_shown_as_unavailable_never_as_zero_money(): void
    {
        $this->lead($this->a, $this->p['a1']);
        $data = $this->report($this->a, ['kpi_volume', 'kpi_cost', 'kpi_revenue', 'revenue'], ['template' => 'management']);

        $this->assertNull($data['kpis']['spend']);
        $this->assertNull($data['kpis']['cpl']);
        $this->assertNull($data['kpis']['cac']);
        $this->assertNull($data['kpis']['roas']);
        $this->assertNull($data['revenue']['total']);
        $this->assertNull($data['kpis']['conversion_rate'] === 0 ? null : null);
        $this->assertSame(1, $data['kpis']['leads']);
        $this->assertSame(0, $data['kpis']['won']);
    }

    public function test_an_empty_company_returns_nothing_pretended_in_any_section(): void
    {
        $data = $this->report($this->a, ['kpi_volume', 'kpi_cost', 'funnel', 'marketing', 'campaigns', 'sources', 'sales', 'lead_quality', 'loss', 'projects', 'units', 'tracking_hub', 'revenue', 'attention'], ['template' => 'management']);

        $this->assertSame(0, $data['kpis']['leads']);
        $this->assertNull($data['kpis']['conversion_rate']);
        $this->assertFalse(collect($data['funnel']['stages'])->firstWhere('key', 'awareness')['available']);
        $this->assertSame([], $data['marketing']['platform_reported']);
        $this->assertSame([], $data['marketing']['mohtam_attributed']);
        $this->assertSame(0, $data['campaigns']['total']);
        $this->assertSame([], $data['sources']['rows']);
        $this->assertNull($data['sales']['avg_first_response_minutes']);
        $this->assertNull($data['loss']['rate']);
        $this->assertFalse($data['units']['available']);
        $this->assertFalse($data['tracking_hub']['available']);
        $this->assertNull($data['revenue']['total']);
        $this->assertNull($data['revenue']['average_deal']);
    }
}
