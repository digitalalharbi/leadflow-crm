<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\Role;
use App\Models\AutomationActionLog;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadMessage;
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
                'full_name' => 'عميل '.self::uniqueDigits(3), 'phone' => '0551234567',
                'phone_normalized' => '+9665512'.self::uniqueDigits(5), 'status' => 'new', 'platform' => 'facebook',
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
    // ------------------------------------------------------------ what automation did

    private function message(Lead $lead, string $direction, ?int $userId = null, string $status = 'sent'): void
    {
        $this->asTenant($this->t['tenant'], fn () => LeadMessage::create([
            'lead_id' => $lead->id, 'user_id' => $userId, 'direction' => $direction === 'in' ? MessageDirection::Inbound : MessageDirection::Outbound,
            'channel' => MessageChannel::Whatsapp, 'provider' => 'mock', 'message_type' => 'text', 'body' => 'x', 'status' => $status,
        ]));
    }

    public function test_automation_coverage_and_human_intervention_are_shares_of_the_periods_leads(): void
    {
        $a = $this->lead();
        $b = $this->lead();
        $c = $this->lead();
        $this->lead();
        $this->message($a, 'out');                                              // automation reached a
        $this->message($b, 'out');                                              // ... and b
        $this->message($b, 'out', $this->t['admin']->id);                       // a person wrote to b too
        $this->message($c, 'out', status: 'failed');                            // a failed send reached nobody

        $auto = $this->center()['automation'];

        $this->assertSame(4, $auto['leads']);
        $this->assertEquals(50.0, $auto['coverage_percent'], 'two of four were reached automatically');
        $this->assertEquals(25.0, $auto['human_percent'], 'one of four was touched by a person');
        $this->assertSame(1, $auto['trend'][29]['human']);
        $this->assertSame(2, $auto['trend'][29]['automated']);
    }

    public function test_a_call_by_a_rep_counts_as_a_human_touch(): void
    {
        $lead = $this->lead();
        $this->lead();
        $this->asTenant($this->t['tenant'], fn () => LeadActivity::create(['lead_id' => $lead->id, 'user_id' => $this->t['admin']->id, 'type' => ActivityType::Called]));

        $this->assertEquals(50.0, $this->center()['automation']['human_percent']);
    }

    public function test_time_to_qualify_averages_arrival_to_qualified(): void
    {
        $this->lead(['created_at' => now()->subHours(5), 'qualified_at' => now()->subHours(4), 'qualification_status' => 'qualified']);   // 1h
        $this->lead(['created_at' => now()->subHours(6), 'qualified_at' => now()->subHours(2), 'qualification_status' => 'qualified']);   // 4h
        $this->lead();                                                                                                                      // never qualified

        $auto = $this->center()['automation'];

        $this->assertEqualsWithDelta(9000, $auto['avg_seconds_to_qualify'], 5, 'two and a half hours');
        $this->assertSame(2, $auto['qualified_samples']);
    }

    public function test_recovered_automations_are_the_deliveries_that_still_got_through(): void
    {
        $lead = $this->lead();
        $this->asTenant($this->t['tenant'], function () use ($lead) {
            foreach ([AutomationActionLog::RECOVERED, AutomationActionLog::RECOVERED, AutomationActionLog::NEEDS_HUMAN, AutomationActionLog::COMPLETED] as $result) {
                AutomationActionLog::create(['lead_id' => $lead->id, 'action_type' => 'send_whatsapp', 'result' => $result, 'happened_at' => now()]);
            }
            AutomationActionLog::create(['lead_id' => $lead->id, 'action_type' => 'send_whatsapp', 'result' => AutomationActionLog::RECOVERED, 'happened_at' => now()->subDays(60)]);
        });

        $auto = $this->center()['automation'];

        $this->assertSame(2, $auto['recovered'], 'the old one is outside the period');
        $this->assertSame(1, $auto['needs_human']);
    }

    public function test_lost_for_no_response_means_lost_without_the_customer_ever_answering(): void
    {
        $silent = $this->lead(['status' => 'lost']);
        $answered = $this->lead(['status' => 'lost']);
        $this->lead(['status' => 'lost']);
        $this->asTenant($this->t['tenant'], function () {
            Lead::whereIn('id', Lead::pluck('id'))->get()->each(fn (Lead $l) => $l->forceFill(['lost_at' => now()])->saveQuietly());
        });
        $this->message($answered, 'in');
        $this->message($silent, 'out');

        $lost = $this->center()['automation']['lost'];

        $this->assertSame(3, $lost['total']);
        $this->assertSame(2, $lost['no_response']);
        $this->assertEquals(66.7, $lost['percent']);
    }

    public function test_revenue_is_split_by_the_quality_the_lead_ended_at(): void
    {
        foreach ([['hot', 30_000_000], ['hot', 10_000_000], ['warm', 20_000_000]] as [$temperature, $minor]) {
            $lead = $this->lead(['status' => 'won', 'won_at' => now()->subHour(), 'temperature' => $temperature]);
            $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['deal_value_minor' => $minor])->saveQuietly());
        }

        $rows = collect($this->center()['automation']['revenue_by_quality'])->keyBy('key');

        $this->assertEquals(400000.0, $rows['hot']['revenue']);
        $this->assertSame(2, $rows['hot']['deals']);
        $this->assertEquals(200000.0, $rows['warm']['revenue']);
        $this->assertSame(['hot', 'warm'], $rows->keys()->all());
        $this->assertEquals($this->center()['kpis']['revenue'], $rows->sum('revenue'), 'the split adds up to the revenue tile');
    }

    public function test_an_empty_workspace_says_nothing_rather_than_zero_percent(): void
    {
        $auto = $this->center()['automation'];

        $this->assertNull($auto['coverage_percent']);
        $this->assertNull($auto['human_percent']);
        $this->assertNull($auto['avg_seconds_to_qualify']);
        $this->assertNull($auto['lost']['percent']);
        $this->assertSame([], $auto['revenue_by_quality']);
    }

    public function test_a_rep_is_still_refused_the_manager_screen(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->actingAs($rep)->getJson('/api/v1/dashboard/manager')->assertForbidden();
    }
}
