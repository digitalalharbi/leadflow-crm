<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use App\Models\Partner;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The operations home: today in numbers (now including who needs a person), and the second half — what
 * is healthy and what is leading — each part only for those who may see it.
 */
class OperationsHomeTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Home Co');
    }

    private function lead(array $attributes = [], array $row = []): Lead
    {
        return $this->asTenant($this->t['tenant'], function () use ($attributes, $row) {
            $lead = Lead::create($attributes + ['full_name' => 'عميل', 'phone_normalized' => '+9665'.random_int(10000000, 99999999), 'status' => 'contacted', 'first_contact_at' => now(), 'last_contact_at' => now()->subHours(3), 'assigned_to' => $this->t['admin']->id]);
            DB::table('leads')->where('id', $lead->id)->update($row + ['created_at' => now()->subDay()]);

            return $lead->fresh();
        });
    }

    public function test_today_counts_who_automation_handed_over_and_nobody_has_picked_up(): void
    {
        $waiting = $this->lead([], ['automation_state' => 'handed_off', 'needs_human_reason' => 'replied', 'handed_off_at' => now()->subHour(), 'last_contact_at' => now()->subHours(3)]);
        // Picked up since the handover: not waiting any more.
        $this->lead([], ['automation_state' => 'handed_off', 'needs_human_reason' => 'replied', 'handed_off_at' => now()->subHours(2), 'last_contact_at' => now()->subHour()]);

        $answers = $this->actingAs($this->t['admin'])->getJson('/api/v1/dashboard/ops')->assertOk()->json('data.answers');

        $this->assertSame(1, $answers['needs_human']);
        foreach (['leads_today', 'interested', 'hot', 'sla_late', 'visits_today', 'reservations_pending', 'won_today', 'revenue_today'] as $key) {
            $this->assertArrayHasKey($key, $answers);
        }
        $this->assertNotNull($waiting);
    }

    public function test_the_second_half_is_pictures_of_health_and_who_is_leading(): void
    {
        $campaign = $this->asTenant($this->t['tenant'], function () {
            $c = Campaign::create(['platform' => 'meta', 'name' => 'حملة الشهر', 'origin' => 'manual', 'status' => 'active', 'currency' => 'SAR']);
            MetricSnapshot::create(['platform' => 'meta', 'level' => 'campaign', 'campaign_id' => $c->id, 'date' => now()->subDay()->toDateString(), 'spend_minor' => 100_000, 'currency' => 'SAR']);

            return $c;
        });
        $partner = $this->asTenant($this->t['tenant'], fn () => Partner::create(['name' => 'وسيط الشهر', 'type' => 'broker', 'commission_percent' => 2]));

        foreach (range(1, 6) as $i) {
            $lead = $this->lead($i <= 2 ? ['status' => 'won', 'deal_value_minor' => 100_000_000] : [], ['campaign_ref_id' => $campaign->id, 'platform' => 'meta', 'won_at' => $i <= 2 ? now()->subDays($i) : null, 'partner_id' => $i <= 3 ? $partner->id : null]);

            if ($i <= 3) {
                $this->asTenant($this->t['tenant'], fn () => Reservation::create(['lead_id' => $lead->id, 'status' => 'pending', 'reserved_at' => now()]));
            }
        }

        $home = $this->actingAs($this->t['admin'])->getJson('/api/v1/dashboard/home')->assertOk()->json('data');

        $this->assertSame(1, $home['campaign_health']['total']);
        $this->assertArrayHasKey('classes', $home['campaign_health']);
        $this->assertIsArray($home['tracking_health']['platforms']);
        foreach (['handled_pct', 'failed', 'needs_human', 'rules_active'] as $key) {
            $this->assertArrayHasKey($key, $home['automation_health']);
        }

        $this->assertSame($this->t['admin']->name, $home['sales_ranking'][0]['name']);
        $this->assertSame(6, $home['sales_ranking'][0]['leads']);

        $this->assertCount(30, $home['revenue_trend']['days']);
        $this->assertEquals(2_000_000, $home['revenue_trend']['total']);
        $this->assertEquals(0, $home['revenue_trend']['previous_total']);

        $this->assertSame('meta', $home['tops']['source']['platform']);
        $this->assertEquals(100, $home['tops']['source']['share']);
        $this->assertSame('حملة الشهر', $home['tops']['campaign']['name']);
        $this->assertSame(3, $home['tops']['campaign']['reservations']);
        $this->assertSame('وسيط الشهر', $home['tops']['partner']['name']);
    }

    public function test_each_part_is_only_for_those_who_may_see_it(): void
    {
        $this->lead(['status' => 'won', 'deal_value_minor' => 50_000_000], ['won_at' => now()->subDay(), 'platform' => 'meta']);

        // A rep: their own revenue and the best source — not the team's ranking or the machinery.
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $mine = $this->actingAs($rep)->getJson('/api/v1/dashboard/home')->assertOk()->json('data');
        foreach (['campaign_health', 'tracking_health', 'automation_health', 'sales_ranking'] as $team) {
            $this->assertNull($mine[$team], $team);
        }
        $this->assertNull($mine['tops']['campaign']);
        $this->assertNull($mine['tops']['partner']);

        // Finance sees the money and the campaigns but not the team ranking.
        $finance = $this->actingAs($this->makeUser($this->t['tenant'], Role::Finance))->getJson('/api/v1/dashboard/home')->assertOk()->json('data');
        $this->assertNotNull($finance['revenue_trend']);
        $this->assertNull($finance['automation_health']);

        // Somebody from outside the company sees no team data at all.
        $client = $this->actingAs($this->makeUser($this->t['tenant'], Role::ClientViewer))->getJson('/api/v1/dashboard/home')->assertOk()->json('data');
        $this->assertNull($client['sales_ranking']);
        $this->assertNull($client['campaign_health']);
        $this->assertNull($client['revenue_trend']);

        // Another workspace sees none of it.
        $other = $this->provisionTenant('Else');
        $this->assertEquals(0, $this->actingAs($other['admin'])->getJson('/api/v1/dashboard/home')->json('data.revenue_trend.total'));
    }
}
