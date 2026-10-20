<?php

namespace Tests\Feature;

use App\Domain\Automation\Thresholds;
use App\Domain\Leads\InterestSla;
use App\Domain\Sla\InterestSlaSweeper;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\Project;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The interested queue: how long an interested lead may wait, by how far along they are; who is
 * worked first; and what the rep's one press does to the lead.
 */
class InterestedQueueTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Queue RE Co');
        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس', 'location' => 'حي النرجس، الرياض']));
        Thresholds::flush();
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], function () use ($attributes) {
            $lead = Lead::create(array_diff_key($attributes, ['level' => 1, 'since' => 1]) + [
                'full_name' => 'سلمان '.random_int(10, 99), 'phone' => '0551234567', 'phone_normalized' => '+9665512'.random_int(10000, 99999),
                'project_id' => $this->project->id, 'status' => 'contacted', 'assigned_to' => $this->t['admin']->id,
            ]);

            // Straight to the column: what is under test is the clock, not the engine that sets the level.
            DB::table('leads')->where('id', $lead->id)->update([
                'interest_level' => $attributes['level'] ?? 'cold',
                'interest_level_changed_at' => $attributes['since'] ?? now(),
                'created_at' => now()->subDay(),
            ]);

            return $lead->fresh();
        });
    }

    private function sla(Lead $lead): ?array
    {
        return $this->asTenant($this->t['tenant'], fn () => InterestSla::for($lead->fresh()));
    }

    public function test_each_level_has_its_own_window(): void
    {
        $this->assertSame(
            ['hot' => 15, 'interested' => 60, 'visit_ready' => 30, 'reservation_ready' => 5],
            collect(InterestSla::KEYS)->map(fn ($key) => $this->asTenant($this->t['tenant'], fn () => Thresholds::workspace($key)))->all(),
        );
    }

    public function test_the_window_is_the_workspaces_and_the_project_can_override_it(): void
    {
        $lead = $this->lead(['level' => 'hot', 'since' => now()->subMinutes(1)]);
        $this->assertSame(15, $this->sla($lead)['target_minutes']);

        $this->actingAs($this->t['admin'])->patchJson('/api/v1/settings', ['sla_hot_minutes' => 40])->assertOk();
        Thresholds::flush();
        $this->assertSame(40, $this->sla($lead)['target_minutes']);

        $this->asTenant($this->t['tenant'], fn () => $this->project->update(['automation_settings' => ['sla_hot_minutes' => 10]]));
        Thresholds::flush();
        $this->assertSame(10, $this->sla($lead)['target_minutes']);
    }

    public function test_the_clock_runs_ok_then_due_soon_then_breached_then_stops_on_contact(): void
    {
        $this->assertSame('ok', $this->sla($this->lead(['level' => 'hot', 'since' => now()->subMinutes(2)]))['state']);
        $this->assertSame('due_soon', $this->sla($this->lead(['level' => 'hot', 'since' => now()->subMinutes(13)]))['state']);

        $late = $this->lead(['level' => 'hot', 'since' => now()->subMinutes(40)]);
        $this->assertSame('breached', $this->sla($late)['state']);
        $this->assertSame(-25, $this->sla($late)['minutes_left']);

        DB::table('leads')->where('id', $late->id)->update(['last_contact_at' => now()]);
        $this->assertSame('done', $this->sla($late)['state']);
    }

    public function test_a_customer_who_writes_again_starts_the_clock_again(): void
    {
        $lead = $this->lead(['level' => 'interested', 'since' => now()->subHours(3)]);
        // A person said so: the engine keeps the floor when the new message is read.
        DB::table('leads')->where('id', $lead->id)->update(['last_contact_at' => now()->subHours(2), 'interest_override' => 'interested']);
        $this->assertSame('done', $this->sla($lead)['state']);

        $this->asTenant($this->t['tenant'], fn () => LeadMessage::create([
            'lead_id' => $lead->id, 'direction' => 'inbound', 'channel' => 'whatsapp', 'body' => 'متى أقدر أشوف الوحدة؟', 'status' => 'received',
        ]));

        $after = $this->sla($lead);
        $this->assertNotNull($this->asTenant($this->t['tenant'], fn () => $lead->fresh()->last_customer_message_at));
        $this->assertSame('ok', $after['state']);
        $this->assertGreaterThan(55, $after['minutes_left']);
    }

    public function test_cold_and_closed_leads_have_no_clock(): void
    {
        $this->assertNull($this->sla($this->lead(['level' => 'warm'])));
        $this->assertNull($this->sla($this->lead(['level' => 'hot', 'status' => 'won'])));
    }

    public function test_a_late_reservation_request_is_worked_before_a_late_warm_lead_and_says_why(): void
    {
        $this->lead(['level' => 'warm', 'score' => 90, 'since' => now()->subHours(5)]);
        $reservation = $this->lead(['level' => 'reservation_ready', 'score' => 10, 'since' => now()->subMinutes(30)]);

        $data = $this->actingAs($this->t['admin'])->getJson('/api/v1/work/next')->assertOk()->json('data');

        $this->assertSame($reservation->uuid, $data['lead']['id']);
        $this->assertSame('critical', $data['action']['urgency']);
        $this->assertSame('breached', $data['sla']['state']);
        $this->assertSame('reservation_ready', $data['interest']['level']);
        $this->assertStringContainsString('مهلة', $data['action']['reason']);
        $this->assertSame('حي النرجس، الرياض', $data['profile']['location']);
        $this->assertStringContainsString('google.com/maps', $data['profile']['map_url']);
    }

    public function test_the_card_carries_what_the_rep_needs_to_start_the_call(): void
    {
        $lead = $this->lead(['level' => 'hot', 'budget_max' => 900000, 'preferred_district' => 'النرجس', 'purchase_purpose' => 'investment', 'since' => now()->subMinutes(20)]);

        $profile = $this->actingAs($this->t['admin'])->getJson('/api/v1/work/next')->json('data.profile');

        $this->assertSame('النرجس', $profile['project']);
        $this->assertEquals(900000, $profile['budget_max']);
        $this->assertSame('النرجس', $profile['district']);
        $this->assertSame('investment', $profile['purpose']);
        $this->assertArrayHasKey('waiting_since', $profile);
        $this->assertArrayNotHasKey('bedrooms', $profile, 'what the lead has not said is left out, not guessed');
        $this->assertNotNull($lead);
    }

    public function test_interested_not_interested_and_reservation_outcomes_do_what_they_say(): void
    {
        $a = $this->lead(['level' => 'warm']);
        $this->actingAs($this->t['admin'])->postJson('/api/v1/work/outcome', ['lead_id' => $a->uuid, 'outcome' => 'interested'])->assertOk();
        $this->assertSame('interested', $this->asTenant($this->t['tenant'], fn () => $a->fresh()->interest_override));
        $this->assertSame('interested', $this->asTenant($this->t['tenant'], fn () => $a->fresh()->interest_level));

        $b = $this->lead(['level' => 'hot']);
        $this->actingAs($this->t['admin'])->postJson('/api/v1/work/outcome', ['lead_id' => $b->uuid, 'outcome' => 'not_interested'])->assertOk();
        $this->assertSame('cold', $this->asTenant($this->t['tenant'], fn () => $b->fresh()->interest_level));

        $c = $this->lead(['level' => 'visit_ready']);
        $this->actingAs($this->t['admin'])->postJson('/api/v1/work/outcome', ['lead_id' => $c->uuid, 'outcome' => 'reservation'])->assertOk();
        $this->asTenant($this->t['tenant'], function () use ($c) {
            $this->assertSame(1, Reservation::where('lead_id', $c->id)->where('status', 'pending')->count());
            $this->assertSame('reservation_ready', $c->fresh()->interest_level);
            $this->assertSame('negotiation', $c->fresh()->status->value);
        });

        // Pressing it twice does not hold two units.
        $this->actingAs($this->t['admin'])->postJson('/api/v1/work/outcome', ['lead_id' => $c->uuid, 'outcome' => 'reservation'])->assertOk();
        $this->assertSame(1, $this->asTenant($this->t['tenant'], fn () => Reservation::where('lead_id', $c->id)->count()));
    }

    public function test_the_sweep_tells_the_owner_once_and_the_manager_when_it_is_late(): void
    {
        $rep = $this->asTenant($this->t['tenant'], fn () => $this->makeUser($this->t['tenant'], Role::Sales));
        $lead = $this->lead(['level' => 'hot', 'since' => now()->subMinutes(13), 'assigned_to' => $rep->id]);

        $sweep = fn () => app(InterestSlaSweeper::class)->sweep($this->t['tenant']);

        $this->assertSame(['due_soon' => 1, 'breached' => 0], $sweep());
        $this->assertSame(['due_soon' => 0, 'breached' => 0], $sweep(), 'said once');

        DB::table('leads')->where('id', $lead->id)->update(['interest_level_changed_at' => now()->subMinutes(30)]);
        $this->assertSame(['due_soon' => 0, 'breached' => 1], $sweep());
        $this->assertSame(['due_soon' => 0, 'breached' => 0], $sweep());

        $kinds = fn ($user) => DB::table('notifications')->where('notifiable_id', $user->id)->get()->map(fn ($n) => json_decode($n->data, true)['kind'] ?? null)->all();
        $this->assertEqualsCanonicalizing(['interest_sla_due_soon', 'interest_sla_breached'], $kinds($rep));
        $this->assertSame(['interest_sla_escalated'], $kinds($this->t['admin']));
    }
}
