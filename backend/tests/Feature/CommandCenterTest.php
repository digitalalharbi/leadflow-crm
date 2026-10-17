<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The screen that says what needs a person right now.
 *
 * Three promises. It lists only what is actually wrong — a zero is not a row.
 * It recommends the same lead the work queue would open. And it shows a rep
 * their own world, not their colleagues'.
 */
class CommandCenterTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Command Co');
        $this->t['tenant']->settings->forceFill(['first_contact_target_minutes' => 30])->save();
    }

    private function lead(array $attributes = [], int $ageMinutes = 1): Lead
    {
        return $this->asTenant($this->t['tenant'], function () use ($attributes, $ageMinutes) {
            $lead = Lead::create(array_merge([
                'full_name' => 'عميل '.random_int(100, 999),
                'phone_normalized' => '+9665000'.random_int(10000, 99999),
                'status' => 'new',
                'assigned_to' => $this->t['admin']->id,
            ], $attributes));

            $lead->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->saveQuietly();

            return $lead->fresh();
        });
    }

    private function command($as = null)
    {
        return $this->actingAs($as ?? $this->t['admin'])->getJson('/api/v1/dashboard/command');
    }

    private function keys($response): array
    {
        return collect($response->json('data.attention'))->pluck('key')->all();
    }

    public function test_a_quiet_workspace_lists_nothing(): void
    {
        $this->lead(['status' => 'contacted', 'first_contact_at' => now()], ageMinutes: 5);

        $response = $this->command()->assertOk();

        $this->assertSame([], $response->json('data.attention'));
    }

    public function test_a_lead_past_the_first_contact_target_is_critical(): void
    {
        $this->lead(ageMinutes: 90);

        $row = collect($this->command()->json('data.attention'))->firstWhere('key', 'sla_breached');

        $this->assertSame(1, $row['count']);
        $this->assertSame('critical', $row['severity']);
        $this->assertSame(30, $row['minutes']);
    }

    public function test_a_lead_inside_the_target_is_not_flagged(): void
    {
        $this->lead(ageMinutes: 5);

        $this->assertNotContains('sla_breached', $this->keys($this->command()));
    }

    public function test_overdue_follow_ups_and_hot_leads_are_counted(): void
    {
        $this->lead(['status' => 'contacted', 'first_contact_at' => now(), 'next_follow_up_at' => now()->subDay()]);
        $this->lead(['status' => 'contacted', 'first_contact_at' => now(), 'temperature' => 'hot']);

        $keys = $this->keys($this->command());

        $this->assertContains('overdue_follow_ups', $keys);
        $this->assertContains('hot', $keys);
    }

    public function test_closed_leads_never_need_attention(): void
    {
        $this->lead(['status' => 'won', 'temperature' => 'hot'], ageMinutes: 500);
        $this->lead(['status' => 'lost'], ageMinutes: 500);

        $this->assertSame([], $this->command()->json('data.attention'));
    }

    public function test_the_recommendation_is_the_lead_the_work_queue_would_open(): void
    {
        $this->lead(['score' => 10], ageMinutes: 5);
        $urgent = $this->lead(['score' => 90], ageMinutes: 300);

        $queue = $this->actingAs($this->t['admin'])->getJson('/api/v1/work/next')->json('data.lead.id');
        $recommended = $this->command()->json('data.recommended.0');

        $this->assertSame($queue, $recommended['id']);
        $this->assertSame($urgent->uuid, $recommended['id']);
        $this->assertNotEmpty($recommended['reason']);
    }

    public function test_at_most_five_are_recommended(): void
    {
        foreach (range(1, 8) as $i) {
            $this->lead(ageMinutes: 100 + $i);
        }

        $this->assertCount(5, $this->command()->json('data.recommended'));
    }

    public function test_failed_automation_runs_count_only_for_the_last_week(): void
    {
        $lead = $this->lead();

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            $rule = AutomationRule::create([
                'name' => 'قاعدة', 'trigger' => 'lead.created', 'conditions' => [], 'actions' => [], 'is_enabled' => true,
            ]);

            $recent = AutomationRun::create(['lead_id' => $lead->id, 'automation_rule_id' => $rule->id,
                'trigger' => 'lead.created', 'outcome' => 'failed']);
            $old = AutomationRun::create(['lead_id' => $lead->id, 'automation_rule_id' => $rule->id,
                'trigger' => 'lead.created', 'outcome' => 'failed']);
            $old->forceFill(['created_at' => now()->subDays(20)])->saveQuietly();
        });

        $row = collect($this->command()->json('data.attention'))->firstWhere('key', 'automation_failures');

        $this->assertSame(1, $row['count']);
    }

    public function test_todays_appointments_are_listed_in_order(): void
    {
        $lead = $this->lead(['status' => 'contacted', 'first_contact_at' => now()]);

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            foreach ([[now()->endOfDay()->subMinutes(5), 'مساءً'], [now()->startOfDay()->addMinutes(5), 'صباحًا']] as [$at, $where]) {
                Appointment::create([
                    'lead_id' => $lead->id, 'assigned_to' => $this->t['admin']->id,
                    'scheduled_at' => $at, 'status' => 'scheduled', 'location' => $where,
                ]);
            }
        });

        $places = collect($this->command()->json('data.appointments_today'))->pluck('location')->all();

        $this->assertSame(['صباحًا', 'مساءً'], $places);
    }

    public function test_a_rep_sees_their_own_world_only(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $other = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->lead(['assigned_to' => $other->id], ageMinutes: 300);
        $mine = $this->lead(['assigned_to' => $rep->id], ageMinutes: 300);

        $response = $this->command($rep)->assertOk();

        $this->assertSame([$mine->uuid], collect($response->json('data.recommended'))->pluck('id')->all());
        // The league table of colleagues' backlogs is for people who see everyone.
        $this->assertSame([], $response->json('data.workload'));
        $this->assertNotContains('unassigned', $this->keys($response));
    }

    public function test_a_manager_sees_who_is_carrying_what(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $this->lead(['assigned_to' => $rep->id, 'status' => 'contacted', 'first_contact_at' => now(), 'temperature' => 'hot']);
        $this->lead(['assigned_to' => $rep->id, 'status' => 'contacted', 'first_contact_at' => now()]);

        $row = collect($this->command()->json('data.workload'))->firstWhere('id', $rep->uuid);

        $this->assertSame(2, $row['open']);
        $this->assertSame(1, $row['hot']);
    }

    public function test_one_workspace_never_sees_anothers(): void
    {
        $other = $this->provisionTenant('Elsewhere');
        $this->asTenant($other['tenant'], fn () => Lead::create([
            'full_name' => 'غريب', 'phone_normalized' => '+966500000001', 'status' => 'new',
        ]));

        $response = $this->command()->assertOk();

        $this->assertSame(0, $response->json('data.open_leads'));
        $this->assertSame([], $response->json('data.recommended'));
    }
}
