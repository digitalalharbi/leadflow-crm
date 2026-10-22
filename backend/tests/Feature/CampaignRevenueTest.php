<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use App\Models\Project;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * From the riyal spent to the riyal earned: every figure on the revenue screen is the one on the
 * campaign list, and each step of the funnel is "reached this one or any later one".
 */
class CampaignRevenueTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Campaign $good;

    private Campaign $wasteful;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Revenue Co');
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));

        [$this->good, $this->wasteful] = $this->asTenant($this->t['tenant'], fn () => [
            Campaign::create(['platform' => 'meta', 'name' => 'حملة جيدة', 'origin' => 'manual', 'status' => 'active', 'project_id' => $project->id, 'currency' => 'SAR']),
            Campaign::create(['platform' => 'tiktok', 'name' => 'حملة مهدرة', 'origin' => 'manual', 'status' => 'active', 'currency' => 'SAR']),
        ]);

        $this->asTenant($this->t['tenant'], function () {
            foreach ([[$this->good, 100_000], [$this->wasteful, 50_000]] as [$campaign, $spend]) {
                MetricSnapshot::create(['platform' => $campaign->platform, 'level' => 'campaign', 'campaign_id' => $campaign->id, 'date' => now()->subDays(2)->toDateString(), 'spend_minor' => $spend, 'impressions' => 1000, 'clicks' => 100, 'currency' => 'SAR']);
            }
        });
    }

    /** @param  array<string,mixed>  $attributes */
    private function lead(Campaign $campaign, array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], function () use ($campaign, $attributes) {
            $lead = Lead::create($attributes + ['full_name' => 'عميل', 'phone_normalized' => '+9665512'.random_int(10000, 99999), 'status' => 'contacted']);
            DB::table('leads')->where('id', $lead->id)->update(['campaign_ref_id' => $campaign->id, 'created_at' => now()->subDay()]);

            return $lead;
        });
    }

    private function data(string $query = '?days=7'): array
    {
        return $this->actingAs($this->t['admin'])->getJson("/api/v1/campaigns/revenue{$query}")->assertOk()->json('data');
    }

    public function test_the_table_runs_from_spend_to_revenue_with_the_cost_of_each_step(): void
    {
        // 6 leads: 5 qualified, 4 interested, 3 visited, 2 reserved, 1 won for 500,000.
        $this->lead($this->good);
        $this->lead($this->good, ['qualification_status' => 'qualified']);
        $this->asTenant($this->t['tenant'], fn () => DB::table('leads')->where('id', $this->lead($this->good)->id)->update(['interest_level' => 'hot']));
        $visited = $this->lead($this->good);
        $this->asTenant($this->t['tenant'], fn () => DB::table('appointments')->insert(['uuid' => (string) str()->uuid(), 'tenant_id' => $this->t['tenant']->id, 'lead_id' => $visited->id, 'scheduled_at' => now()->subDay(), 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]));
        $held = $this->lead($this->good);
        $this->asTenant($this->t['tenant'], fn () => Reservation::create(['lead_id' => $held->id, 'status' => 'pending']));
        $this->lead($this->good, ['status' => 'won', 'deal_value_minor' => 50_000_000]);

        $row = collect($this->data()['campaigns'])->firstWhere('name', 'حملة جيدة');

        $this->assertEquals(1000.0, $row['spend']);
        $this->assertSame(6, $row['leads']);
        $this->assertSame(5, $row['qualified']);
        $this->assertSame(4, $row['interested']);
        $this->assertSame(3, $row['visits']);
        $this->assertSame(2, $row['reservations']);
        $this->assertSame(1, $row['won']);
        $this->assertEquals(500000, $row['revenue']);
        $this->assertEquals(166.67, $row['cpl']);
        $this->assertEquals(250.0, $row['cost_per_interested']);
        $this->assertEquals(333.33, $row['cost_per_visit']);
        $this->assertEquals(500.0, $row['cost_per_reservation']);
        $this->assertEquals(1000.0, $row['cac']);
        $this->assertEquals(500.0, $row['roas']);
    }

    public function test_the_funnel_totals_and_each_step_says_what_it_cost_and_what_was_lost(): void
    {
        $this->lead($this->good);
        $this->lead($this->good, ['status' => 'won', 'deal_value_minor' => 10_000_000]);
        $this->lead($this->wasteful);

        $data = $this->data();
        $funnel = collect($data['funnel'])->keyBy('key');

        $this->assertSame(['leads', 'qualified', 'interested', 'visits', 'reservations', 'won'], collect($data['funnel'])->pluck('key')->all());
        $this->assertSame(3, $funnel['leads']['count']);
        $this->assertSame(1, $funnel['won']['count']);
        $this->assertEquals(1500.0, $funnel['won']['cost'], 'one customer cost the whole 1,500');
        $this->assertEquals(33.3, $funnel['won']['rate']);
        $this->assertEquals(0.0, $funnel['won']['lost'], 'nothing is lost between the last two steps here');
        $this->assertEquals(66.7, $funnel['qualified']['lost']);

        // Never a step bigger than the one before it.
        $counts = collect($data['funnel'])->pluck('count')->all();
        $this->assertSame($counts, collect($counts)->sortDesc()->values()->all());
    }

    public function test_money_that_bought_nothing_is_called_out_and_the_best_return_is_ranked(): void
    {
        $this->lead($this->good, ['status' => 'won', 'deal_value_minor' => 30_000_000]);
        $this->lead($this->wasteful);

        $rankings = $this->data()['rankings'];

        $this->assertSame('حملة جيدة', $rankings['roas'][0]['name']);
        $this->assertSame('حملة مهدرة', $rankings['wasted'][0]['name'], 'spend and nothing qualified');
        $this->assertCount(1, $rankings['wasted']);
    }

    public function test_it_compares_with_the_period_before(): void
    {
        // Spend 1,000 in the last 7 days; 500 in the 7 before.
        $this->asTenant($this->t['tenant'], fn () => MetricSnapshot::create(['platform' => 'meta', 'level' => 'campaign', 'campaign_id' => $this->good->id, 'date' => now()->subDays(10)->toDateString(), 'spend_minor' => 50_000, 'currency' => 'SAR']));

        $comparison = $this->data()['comparison'];

        $this->assertEquals(1500.0, (float) $comparison['spend']['current']);
        $this->assertEquals(500.0, (float) $comparison['spend']['previous']);
        $this->assertEquals(200.0, $comparison['spend']['change']);
        // Nothing before: no percentage rather than infinity.
        $this->assertNull($comparison['leads']['change']);
    }

    public function test_the_heatmap_trend_and_mix_cover_the_period(): void
    {
        $this->lead($this->good, ['status' => 'won', 'deal_value_minor' => 10_000_000]);
        $this->lead($this->good);

        $data = $this->data();

        $this->assertSame(['qualified', 'interested', 'visits', 'reservations', 'won'], $data['heatmap']['steps']);
        $cells = collect($data['heatmap']['rows'])->firstWhere('name', 'حملة جيدة')['cells'];
        $this->assertEquals([50, 50, 50, 50, 50], $cells);

        $this->assertCount(7, $data['trend'], 'every day of the period is present');
        $day = collect($data['trend'])->firstWhere('date', now()->subDay()->toDateString());
        $this->assertSame(2, $day['leads']);
        $this->assertSame(1, $day['won']);
        $this->assertEquals(100000, $day['revenue']);
        $this->assertEquals(1500.0, collect($data['trend'])->sum('spend'));

        $this->assertEqualsCanonicalizing(['meta', 'tiktok'], collect($data['mix']['platforms'])->pluck('key')->all());
        $this->assertSame('حملة جيدة', $data['mix']['revenue_by_campaign'][0]['label']);
    }

    public function test_only_people_who_can_read_reports_see_it(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->actingAs($rep)->getJson('/api/v1/campaigns/revenue')->assertForbidden();
    }

    public function test_one_workspace_never_sees_anothers_campaigns(): void
    {
        $this->lead($this->good);
        $other = $this->provisionTenant('Rival Co');

        $this->assertSame([], $this->actingAs($other['admin'])->getJson('/api/v1/campaigns/revenue')->assertOk()->json('data.campaigns'));
    }
}
