<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\LeadActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The manager's one screen: every figure the same as the report it summarises. */
class ManagerCommandCenterTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Manager Co');
    }

    private function lead(array $attributes = []): Lead
    {
        $guarded = array_intersect_key($attributes, array_flip(['created_at', 'qualified_at', 'won_at', 'first_response_at', 'qualification_status']));

        return $this->asTenant($this->t['tenant'], function () use ($attributes, $guarded) {
            $lead = Lead::create(array_merge([
                'full_name' => 'عميل '.random_int(100, 999), 'phone' => '0551234567',
                'phone_normalized' => '+9665512'.random_int(10000, 99999), 'status' => 'new', 'platform' => 'facebook',
            ], array_diff_key($attributes, $guarded)));
            if ($guarded) {
                $lead->forceFill($guarded)->saveQuietly();
            }

            return $lead;
        });
    }

    private function center(array $query = []): array
    {
        return $this->actingAs($this->t['admin'])->getJson('/api/v1/dashboard/manager?'.http_build_query($query))->assertOk()->json('data');
    }

    public function test_it_carries_every_tile_a_manager_looks_for(): void
    {
        $data = $this->center();

        foreach (['leads_today', 'leads_period', 'qualified', 'overdue_follow_ups', 'stalled', 'avg_response_seconds', 'appointment_rate', 'conversion_rate', 'revenue'] as $key) {
            $this->assertArrayHasKey($key, $data['kpis'], $key);
        }
        foreach (['sla', 'funnel', 'temperature', 'sources', 'team', 'trend'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
        $this->assertSame(['leads', 'valid', 'engaged', 'qualified', 'appointments', 'won'], array_column($data['funnel'], 'key'));
    }

    public function test_the_figures_count_what_happened(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $this->lead();
        $this->lead(['status' => 'qualified', 'qualification_status' => 'qualified', 'qualified_at' => now()->subDay(), 'assigned_to' => $rep->id]);
        $this->lead(['status' => 'appointment', 'assigned_to' => $rep->id]);
        $won = $this->lead(['status' => 'won', 'assigned_to' => $rep->id, 'won_at' => now()->subHours(3)]);
        $this->asTenant($this->t['tenant'], fn () => $won->forceFill(['deal_value_minor' => 50_000_000])->saveQuietly());
        $this->lead(['status' => 'contacted', 'next_follow_up_at' => now()->subDay(), 'assigned_to' => $rep->id]);

        $data = $this->center();
        $k = $data['kpis'];

        $this->assertSame(5, $k['leads_period']);
        $this->assertSame(5, $k['leads_today']);
        $this->assertSame(3, $k['qualified'], 'qualified, appointment and won all count');
        $this->assertSame(1, $k['overdue_follow_ups']);
        $this->assertEquals(40.0, $k['appointment_rate']);
        $this->assertEquals(20.0, $k['conversion_rate']);
        $this->assertEquals(500000.0, $k['revenue']);
        $this->assertSame(5, $data['funnel'][0]['count']);
        $this->assertSame(1, $data['funnel'][5]['count']);
    }

    public function test_the_team_is_ranked_by_sales_with_what_each_carries(): void
    {
        $best = $this->makeUser($this->t['tenant'], Role::Sales);
        $other = $this->makeUser($this->t['tenant'], Role::Sales);
        $this->lead(['status' => 'won', 'assigned_to' => $best->id, 'won_at' => now()]);
        $this->lead(['status' => 'won', 'assigned_to' => $best->id, 'won_at' => now()]);
        $this->lead(['status' => 'contacted', 'assigned_to' => $other->id, 'next_follow_up_at' => now()->subDay(), 'temperature' => 'hot']);

        $team = $this->center()['team'];

        $this->assertSame($best->uuid, $team[0]['id']);
        $this->assertSame(2, $team[0]['won']);
        $this->assertSame($other->uuid, $team[1]['id']);
        $this->assertSame(1, $team[1]['open']);
        $this->assertSame(1, $team[1]['overdue']);
        $this->assertSame(1, $team[1]['hot']);
    }

    public function test_the_trend_has_every_day_and_the_period_can_be_chosen(): void
    {
        $this->lead(['created_at' => now()->subDays(2)]);
        $this->lead();

        $trend = $this->center(['days' => 7])['trend'];

        $this->assertCount(7, $trend);
        $this->assertSame(now()->toDateString(), $trend[6]['date']);
        $this->assertSame(1, $trend[6]['leads']);
        $this->assertSame(1, $trend[4]['leads']);
        $this->assertCount(30, $this->center(['days' => 999])['trend'], 'an odd period falls back to 30');
    }

    public function test_the_sources_are_mixed_and_a_leads_stage_clock_and_stalled_are_the_same_as_elsewhere(): void
    {
        foreach (range(1, 3) as $i) {
            $this->lead(['platform' => 'tiktok']);
        }
        $this->lead(['platform' => 'facebook']);
        $stalled = $this->lead(['status' => 'contacted', 'created_at' => now()->subDays(10)]);
        $this->asTenant($this->t['tenant'], fn () => $stalled->forceFill(['last_contact_at' => now()->subDays(8)])->saveQuietly());

        $data = $this->center(['days' => 30]);

        $this->assertSame(['tiktok', 3], [$data['sources'][0]['key'], $data['sources'][0]['count']]);
        $this->assertSame(1, $data['kpis']['stalled']);
        $this->assertSame($this->actingAs($this->t['admin'])->getJson('/api/v1/dashboard/command')->json('data.sla.stalled.count'), $data['kpis']['stalled']);
    }

    public function test_a_response_time_is_measured_from_the_first_human_touch(): void
    {
        $lead = $this->lead(['created_at' => now()->subHours(3)]);
        $this->asTenant($this->t['tenant'], function () use ($lead) {
            $a = LeadActivity::create(['lead_id' => $lead->id, 'user_id' => $this->t['admin']->id, 'type' => ActivityType::Called]);
            $a->forceFill(['created_at' => now()->subHours(3)->addMinutes(20)])->saveQuietly();
        });

        $this->assertEqualsWithDelta(1200, $this->center()['kpis']['avg_response_seconds'], 5);
    }

    public function test_only_managers_and_admins_see_it_and_only_their_workspace(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $other = $this->provisionTenant('Other Co');
        $this->asTenant($other['tenant'], fn () => Lead::create(['full_name' => 'س', 'phone' => '0551111111', 'phone_normalized' => '+966551111111', 'status' => 'new']));

        $this->actingAs($rep)->getJson('/api/v1/dashboard/manager')->assertForbidden();
        $this->assertSame(0, $this->center()['kpis']['leads_period'], 'another workspace\'s leads are not counted');
    }
}
