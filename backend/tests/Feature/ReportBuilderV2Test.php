<?php

namespace Tests\Feature;

use App\Domain\Insights\Recommendations;
use App\Domain\Reports\ReportTemplates;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\MetricSnapshot;
use App\Models\Partner;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The report builder: sections in the order the owner chose, each with its own switch, audience
 * and period; compared with the period before; and a link that can tell how often it was opened.
 */
class ReportBuilderV2Test extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Builder Co');
    }

    private function lead(array $attributes = [], string $daysAgo = '1'): Lead
    {
        return $this->asTenant($this->t['tenant'], function () use ($attributes, $daysAgo) {
            $lead = Lead::create($attributes + ['full_name' => 'عميل', 'phone_normalized' => '+9665512'.random_int(10000, 99999), 'status' => 'contacted', 'assigned_to' => $this->t['admin']->id]);
            DB::table('leads')->where('id', $lead->id)->update(['created_at' => now()->subDays((int) $daysAgo)]);

            return $lead->fresh();
        });
    }

    private function make(array $data = []): array
    {
        return $this->actingAs($this->t['admin'])->postJson('/api/v1/report-shares', $data + ['name' => 'تقرير', 'template' => 'executive'])->assertCreated()->json('data.share');
    }

    private function open(array $share, array $headers = [])
    {
        return $this->withHeaders($headers)->getJson('/api/v1/shared/reports/'.basename($share['url']));
    }

    private function layout(array $keys, array $on = [], array $extra = []): array
    {
        return array_map(fn (string $key) => ['key' => $key, 'on' => in_array($key, $on ?: $keys, true)] + ($extra[$key] ?? []), $keys);
    }

    public function test_sections_come_back_in_the_order_the_owner_chose(): void
    {
        $this->lead();
        $share = $this->make(['layout' => $this->layout(['trend', 'funnel', 'kpi_volume'])]);

        $this->assertSame(['trend', 'funnel', 'kpi_volume'], $this->open($share)->assertOk()->json('data.sections'));
        $this->assertSame(['trend', 'funnel', 'kpi_volume'], collect($share['layout'])->where('on', true)->pluck('key')->all());

        // Every section is in the layout, the ones left out switched off at the end.
        $this->assertSame(ReportTemplates::SECTIONS, collect($share['layout'])->pluck('key')->sort()->values()->all() === collect(ReportTemplates::SECTIONS)->sort()->values()->all() ? ReportTemplates::SECTIONS : []);
    }

    public function test_a_section_for_the_team_is_in_the_preview_and_not_on_the_link(): void
    {
        $this->lead();
        $share = $this->make(['layout' => $this->layout(['kpi_volume', 'funnel'], [], ['funnel' => ['visible_to' => 'team']])]);

        $this->assertSame(['kpi_volume'], $this->open($share)->assertOk()->json('data.sections'));
        $this->assertArrayNotHasKey('funnel', $this->open($share)->json('data'));

        $preview = $this->actingAs($this->t['admin'])->getJson("/api/v1/report-shares/{$share['id']}/preview")->assertOk()->json('data');
        $this->assertSame(['kpi_volume', 'funnel'], $preview['sections']);
        $this->assertArrayHasKey('funnel', $preview);

        $this->actingAs($this->makeUser($this->t['tenant'], Role::Sales))->getJson("/api/v1/report-shares/{$share['id']}/preview")->assertForbidden();
    }

    public function test_a_section_can_have_a_period_of_its_own(): void
    {
        $this->lead([], '3');
        $this->lead([], '20');
        $share = $this->make(['days' => 30, 'layout' => $this->layout(['kpi_volume', 'trend'], [], ['kpi_volume' => ['days' => 7]])]);

        $data = $this->open($share)->assertOk()->json('data');

        $this->assertSame(1, $data['kpis']['leads'], 'the figures cover seven days');
        $this->assertSame(30, count($data['trend']), 'the trend still covers the report’s thirty');
        $this->assertSame(2, array_sum(array_column($data['trend'], 'leads')));
    }

    public function test_a_report_can_compare_itself_with_the_period_before(): void
    {
        $this->lead([], '3');
        $this->lead([], '4');
        $this->lead([], '12');
        $share = $this->make(['days' => 7, 'compare' => true, 'layout' => $this->layout(['kpi_volume', 'trend'])]);

        $data = $this->open($share)->assertOk()->json('data');

        $this->assertSame(2, $data['kpis']['leads']);
        $this->assertSame(1, $data['previous']['leads']);
        $this->assertTrue($data['report']['compare']);
        $this->assertSame(CarbonImmutable::today()->subDays(7)->toDateString(), $data['report']['previous']['to']);
        $this->assertCount(7, $data['previous_trend']);
        $this->assertSame(1, array_sum(array_column($data['previous_trend'], 'leads')));

        // Not asked: nothing is computed for the comparison and nothing is in the response.
        $plain = $this->make(['days' => 7, 'layout' => $this->layout(['kpi_volume', 'trend'])]);
        $this->assertArrayNotHasKey('previous', $this->open($plain)->json('data'));
        $this->assertArrayNotHasKey('previous_trend', $this->open($plain)->json('data'));
    }

    public function test_the_refresh_rate_is_kept_and_only_known_ones_are_accepted(): void
    {
        $share = $this->make(['refresh_seconds' => 300]);
        $this->assertSame(300, $share['refresh_seconds']);
        $this->assertSame(300, $this->open($share)->json('data.report.refresh_seconds'));

        $this->actingAs($this->t['admin'])->patchJson("/api/v1/report-shares/{$share['id']}", ['refresh_seconds' => 5])->assertStatus(422);
    }

    public function test_views_are_analysed_and_a_refresh_is_not_another_view(): void
    {
        $share = $this->make();

        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone) Mobile'])->getJson('/api/v1/shared/reports/'.basename($share['url']))->assertOk();
        $this->open($share, ['User-Agent' => 'Mozilla/5.0 (iPhone) Mobile', 'X-Share-Refresh' => '1'])->assertOk();
        // Headers set on a test stay set: this visitor is not refreshing.
        $this->flushHeaders();
        $this->open($share, ['User-Agent' => 'Mozilla/5.0 (Macintosh) Chrome'])->assertOk();

        $stats = $this->actingAs($this->t['admin'])->getJson("/api/v1/report-shares/{$share['id']}/analytics")->assertOk()->json('data');

        $this->assertSame(2, $stats['total']);
        $this->assertSame(2, $stats['unique']);
        $this->assertCount(30, $stats['daily']);
        $this->assertSame(2, end($stats['daily'])['views']);
        $this->assertEqualsCanonicalizing(['mobile', 'desktop'], array_column($stats['recent'], 'device'));
        $this->assertStringNotContainsString('iPhone', json_encode($stats), 'the browser is never stored');
    }

    public function test_the_new_sections_are_pictures_of_the_work_and_carry_no_money_unless_money_is_on(): void
    {
        $hot = $this->lead(['qualification_status' => 'qualified']);
        $this->lead(['qualification_status' => 'partially_qualified']);
        $this->lead();

        $this->asTenant($this->t['tenant'], function () use ($hot) {
            Appointment::create(['lead_id' => $hot->id, 'scheduled_at' => now()->subDay(), 'status' => 'completed']);
            Appointment::create(['lead_id' => $hot->id, 'scheduled_at' => now()->subDay(), 'status' => 'no_show']);
            Reservation::create(['lead_id' => $hot->id, 'status' => 'pending', 'reserved_at' => now()]);
            LeadMessage::create(['lead_id' => $hot->id, 'direction' => 'outbound', 'channel' => 'whatsapp', 'body' => 'آلي', 'status' => 'sent']);
            LeadMessage::create(['lead_id' => $hot->id, 'user_id' => $this->t['admin']->id, 'direction' => 'outbound', 'channel' => 'whatsapp', 'body' => 'شخص', 'status' => 'sent']);
        });

        // Last, because a visit or a reservation moves the level on its own.
        DB::table('leads')->where('id', $hot->id)->update(['interest_level' => 'hot', 'interest_level_changed_at' => now()->subHours(2), 'needs_human_reason' => 'asked']);

        $share = $this->make(['template' => 'client', 'layout' => $this->layout(['interested', 'qualification', 'visits', 'reservations', 'automation', 'attribution', 'tracking_health'])]);
        $data = $this->open($share)->assertOk()->json('data');

        $this->assertSame(['interested', 'qualification', 'visits', 'reservations', 'automation', 'attribution', 'tracking_health'], $data['sections']);

        $this->assertSame(1, collect($data['interested']['levels'])->firstWhere('key', 'hot')['count']);
        $this->assertSame(1, $data['interested']['late'], 'a hot lead nobody answered for two hours is late');
        $this->assertEquals(0, $data['interested']['on_time_pct']);
        $this->assertSame(['qualified' => 1, 'partial' => 1, 'unqualified' => 1, 'total' => 3], array_intersect_key($data['qualification'], array_flip(['qualified', 'partial', 'unqualified', 'total'])));
        $this->assertEquals(33.3, $data['qualification']['rate']);
        $this->assertSame(1, $data['visits']['completed']);
        $this->assertSame(1, $data['visits']['no_show']);
        $this->assertEquals(50, $data['visits']['completion_rate']);
        $this->assertSame(1, $data['reservations']['pending']);
        $this->assertSame(1, $data['automation']['automated']);
        $this->assertSame(1, $data['automation']['human']);
        $this->assertEquals(50, $data['automation']['handled_pct']);
        $this->assertSame(1, $data['automation']['needs_human']);
        $this->assertSame(['first', 'last', 'linear', 'position'], array_keys($data['attribution']['models']));
        $this->assertNotEmpty($data['tracking_health']);

        // Nothing about money: it was not switched on.
        $text = json_encode($data);
        foreach (['"spend"', '"revenue"', '"cpl"', '"roas"'] as $money) {
            $this->assertStringNotContainsString($money, $text);
        }
        // And nothing about a credential in tracking health.
        $this->assertStringNotContainsString('missing', $text);
    }

    public function test_a_partners_report_never_gets_the_sections_that_would_show_other_people(): void
    {
        $partner = $this->asTenant($this->t['tenant'], fn () => Partner::create(['name' => 'وسيط', 'type' => 'broker']));
        $share = $this->make(['template' => 'partner', 'partner_id' => $partner->uuid, 'layout' => $this->layout(ReportTemplates::SECTIONS)]);

        $data = $this->open($share)->assertOk()->json('data');

        foreach (['kpi_cost', 'platforms', 'campaigns', 'attribution', 'team', 'automation', 'tracking_health', 'insights', 'recommendations'] as $never) {
            $this->assertNotContains($never, $data['sections']);
        }
        $this->assertContains('kpi_volume', $data['sections']);
    }

    public function test_a_person_cannot_add_a_section_built_from_what_they_may_not_see(): void
    {
        // A built role that may see reports but never spend.
        $role = $this->actingAs($this->t['admin'])->postJson('/api/v1/access/roles', ['name' => 'Reports No Spend', 'base_role' => 'marketing_manager', 'grants' => [
            'dashboard' => ['view'], 'analytics' => ['view'], 'reports_share' => ['view', 'create', 'edit', 'share'], 'revenue' => ['view'],
        ]])->assertCreated()->json('data.role.id');
        $person = $this->makeUser($this->t['tenant'], Role::MarketingManager);
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/users/{$person->uuid}", ['access_role_id' => $role])->assertOk();
        $person = $person->fresh();

        $directory = $this->actingAs($person)->getJson('/api/v1/report-shares')->assertOk()->json('data');
        $this->assertContains('kpi_cost', $directory['unavailable']);
        $this->assertNotContains('kpi_revenue', $directory['unavailable']);

        $this->actingAs($person)->postJson('/api/v1/report-shares', ['name' => 'x', 'template' => 'executive'])->assertStatus(422);
        $this->actingAs($person)->postJson('/api/v1/report-shares', ['name' => 'x', 'template' => 'client'])->assertCreated();
    }

    public function test_recommendations_compare_campaigns_and_stay_silent_on_too_little(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            $cheap = Campaign::create(['platform' => 'meta', 'name' => 'رخيصة', 'origin' => 'manual', 'status' => 'active', 'currency' => 'SAR']);
            $dear = Campaign::create(['platform' => 'tiktok', 'name' => 'غالية', 'origin' => 'manual', 'status' => 'active', 'currency' => 'SAR']);
            MetricSnapshot::create(['platform' => 'meta', 'level' => 'campaign', 'campaign_id' => $cheap->id, 'date' => now()->subDay()->toDateString(), 'spend_minor' => 200_000, 'currency' => 'SAR']);
            MetricSnapshot::create(['platform' => 'tiktok', 'level' => 'campaign', 'campaign_id' => $dear->id, 'date' => now()->subDay()->toDateString(), 'spend_minor' => 600_000, 'currency' => 'SAR']);

            foreach (range(1, 20) as $i) {
                $lead = Lead::create(['full_name' => "ر{$i}", 'phone_normalized' => '+96655100'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'status' => 'contacted']);
                DB::table('leads')->where('id', $lead->id)->update(['campaign_ref_id' => $cheap->id, 'created_at' => now()->subDay()]);
                $lead = Lead::create(['full_name' => "غ{$i}", 'phone_normalized' => '+96655200'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'status' => 'contacted']);
                DB::table('leads')->where('id', $lead->id)->update(['campaign_ref_id' => $dear->id, 'created_at' => now()->subDay()]);

                if ($i <= 8) {
                    Reservation::create(['lead_id' => $lead->id, 'status' => 'pending', 'reserved_at' => now()]);
                }
            }
        });

        $from = CarbonImmutable::today()->subDays(29)->startOfDay();
        $to = CarbonImmutable::today()->endOfDay();

        $items = $this->asTenant($this->t['tenant'], fn () => (new Recommendations)->build($from, $to, null, true, 'SAR'));
        $keys = array_column($items, 'key');

        $this->assertContains('cheap_but_weak', $keys);
        $found = collect($items)->firstWhere('key', 'cheap_but_weak');
        $this->assertSame('رخيصة', $found['values']['a']);
        $this->assertSame('غالية', $found['values']['b']);
        $this->assertSame(0.0, (float) $found['values']['rate_a']);
        $this->assertEquals(40, $found['values']['rate_b']);
        $this->assertStringStartsWith('/campaigns/', $found['link']);
        $this->assertSame('recommendation', $found['kind']);

        // Without money the money findings are not made.
        $without = array_column($this->asTenant($this->t['tenant'], fn () => (new Recommendations)->build($from, $to, null, false, 'SAR')), 'key');
        $this->assertNotContains('cheap_but_weak', $without);
        $this->assertNotContains('budget_share_gap', $without);

        // And in a report: a public link carries the finding but not the way into the product.
        $share = $this->make(['template' => 'management']);
        $report = $this->open($share)->assertOk()->json('data');
        $this->assertContains('cheap_but_weak', array_column($report['recommendations'], 'key'));
        $this->assertArrayNotHasKey('link', $report['recommendations'][0]);
        $preview = $this->actingAs($this->t['admin'])->getJson("/api/v1/report-shares/{$share['id']}/preview")->json('data');
        $this->assertArrayHasKey('link', $preview['recommendations'][0]);
    }

    public function test_too_few_leads_say_nothing(): void
    {
        $this->lead();
        $this->lead();

        $items = $this->asTenant($this->t['tenant'], fn () => (new Recommendations)->build(CarbonImmutable::today()->subDays(29)->startOfDay(), CarbonImmutable::today()->endOfDay()));

        $this->assertSame([], array_values(array_diff(array_column($items, 'key'), ['sla_late'])));
    }
}
