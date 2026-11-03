<?php

namespace Tests\Feature;

use App\Domain\Analytics\LeadFunnel;
use App\Domain\Visits\ReservationService;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Property;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A visit has a life — asked for, confirmed, moved, done or missed, and a result — and a unit
 * held for a lead is held until it is confirmed, sold, cancelled or lapses.
 */
class VisitsAndReservationsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Project $project;

    private Property $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Visits Co');
        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
        $this->unit = $this->asTenant($this->t['tenant'], fn () => Property::create(['project_id' => $this->project->id, 'code' => 'A-12', 'property_type' => 'apartment', 'title' => 'شقة 12', 'price_minor' => 90_000_000]));
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create($attributes + [
            'full_name' => 'سلمان', 'phone_normalized' => '+9665512'.self::uniqueDigits(5), 'project_id' => $this->project->id,
            'status' => 'contacted', 'assigned_to' => $this->t['admin']->id,
        ]));
    }

    private function api()
    {
        return $this->actingAs($this->t['admin']);
    }

    private function visit(Lead $lead, array $extra = []): string
    {
        return $this->api()->postJson('/api/v1/appointments', ['lead_id' => $lead->uuid, 'scheduled_at' => now()->addDay()->toIso8601String()] + $extra)
            ->assertCreated()->json('data.appointment.id');
    }

    public function test_a_customer_asking_to_visit_becomes_one_requested_visit_without_a_time(): void
    {
        $lead = $this->lead(['appointment_interest' => true]);

        $requests = $this->asTenant($this->t['tenant'], fn () => Appointment::where('lead_id', $lead->id)->get());
        $this->assertCount(1, $requests);
        $this->assertSame('requested', $requests[0]->status);
        $this->assertNull($requests[0]->scheduled_at);
        $this->assertSame('customer', $requests[0]->source);

        // Asking again is still asking once.
        $this->asTenant($this->t['tenant'], fn () => $lead->fresh()->forceFill(['appointment_interest' => false])->save());
        $this->asTenant($this->t['tenant'], fn () => $lead->fresh()->forceFill(['appointment_interest' => true])->save());
        $this->assertSame(1, $this->asTenant($this->t['tenant'], fn () => Appointment::where('lead_id', $lead->id)->count()));
    }

    public function test_booking_a_time_confirms_the_request_instead_of_adding_a_second_visit(): void
    {
        $lead = $this->lead(['appointment_interest' => true]);

        $id = $this->visit($lead);

        $this->assertSame(1, $this->asTenant($this->t['tenant'], fn () => Appointment::where('lead_id', $lead->id)->count()));
        $row = $this->asTenant($this->t['tenant'], fn () => Appointment::where('uuid', $id)->first());
        $this->assertSame('confirmed', $row->status);
        $this->assertNotNull($row->confirmed_at);
        $this->assertSame('appointment', $this->asTenant($this->t['tenant'], fn () => $lead->fresh()->status->value));
    }

    public function test_a_visit_moves_only_where_the_workflow_allows(): void
    {
        $lead = $this->lead();
        $id = $this->visit($lead);

        // Moved twice, then done.
        $this->api()->postJson("/api/v1/appointments/{$id}/transition", ['to' => 'rescheduled', 'scheduled_at' => now()->addDays(2)->toIso8601String()])->assertOk()->assertJsonPath('data.appointment.status', 'rescheduled');
        $this->api()->postJson("/api/v1/appointments/{$id}/transition", ['to' => 'rescheduled', 'scheduled_at' => now()->addDays(3)->toIso8601String()])->assertOk()->assertJsonPath('data.appointment.rescheduled_count', 2);
        $this->api()->postJson("/api/v1/appointments/{$id}/transition", ['to' => 'rescheduled'])->assertStatus(422);
        $this->api()->postJson("/api/v1/appointments/{$id}/transition", ['to' => 'completed'])->assertOk();

        // Done is done: it cannot be cancelled or confirmed again.
        $this->api()->postJson("/api/v1/appointments/{$id}/transition", ['to' => 'cancelled'])->assertStatus(422);
        $this->api()->postJson("/api/v1/appointments/{$id}/transition", ['to' => 'confirmed', 'scheduled_at' => now()->addDay()->toIso8601String()])->assertStatus(422);
    }

    public function test_a_no_show_gets_a_follow_up_and_tells_the_owner(): void
    {
        $lead = $this->lead();
        $id = $this->visit($lead);

        $this->api()->postJson("/api/v1/appointments/{$id}/transition", ['to' => 'no_show'])->assertOk();

        $this->assertSame(1, $this->asTenant($this->t['tenant'], fn () => FollowUp::where('lead_id', $lead->id)->where('status', 'pending')->count()));
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->t['admin']->id)->where('data', 'like', '%visit_no_show%')->count());

        // They can still come back.
        $this->api()->postJson("/api/v1/appointments/{$id}/transition", ['to' => 'rescheduled', 'scheduled_at' => now()->addDays(4)->toIso8601String()])->assertOk();
    }

    public function test_what_came_of_a_visit_moves_the_lead(): void
    {
        $interested = $this->lead();
        $this->api()->postJson('/api/v1/appointments/'.$this->visit($interested).'/outcome', ['outcome' => 'interested'])->assertOk()->assertJsonPath('data.appointment.status', 'completed')->assertJsonPath('data.appointment.outcome', 'interested');
        $this->assertSame('interested', $this->asTenant($this->t['tenant'], fn () => $interested->fresh()->interest_override));

        $followUp = $this->lead();
        $this->api()->postJson('/api/v1/appointments/'.$this->visit($followUp).'/outcome', ['outcome' => 'follow_up'])->assertOk();
        $this->assertSame(1, $this->asTenant($this->t['tenant'], fn () => FollowUp::where('lead_id', $followUp->id)->where('status', 'pending')->count()));

        $lost = $this->lead();
        $this->api()->postJson('/api/v1/appointments/'.$this->visit($lost).'/outcome', ['outcome' => 'lost'])->assertOk();
        $this->assertSame('lost', $this->asTenant($this->t['tenant'], fn () => $lost->fresh()->status->value));

        $reserve = $this->lead();
        $this->api()->postJson('/api/v1/appointments/'.$this->visit($reserve, ['property_id' => $this->unit->uuid]).'/outcome', ['outcome' => 'reservation'])->assertOk();
        $this->asTenant($this->t['tenant'], function () use ($reserve) {
            $reservation = Reservation::where('lead_id', $reserve->id)->firstOrFail();
            $this->assertSame($this->unit->id, $reservation->property_id);
            $this->assertSame('reserved', $this->unit->fresh()->status);
        });

        // Its result is said once.
        $this->api()->postJson('/api/v1/appointments/'.$this->visit($this->lead()).'/outcome', ['outcome' => 'villa'])->assertStatus(422);
    }

    public function test_a_cancelled_or_requested_visit_has_no_outcome(): void
    {
        $lead = $this->lead(['appointment_interest' => true]);
        $id = $this->asTenant($this->t['tenant'], fn () => Appointment::where('lead_id', $lead->id)->value('uuid'));

        $this->api()->postJson("/api/v1/appointments/{$id}/outcome", ['outcome' => 'interested'])->assertStatus(422);
    }

    public function test_the_old_scheduled_status_still_counts_as_a_confirmed_visit(): void
    {
        $lead = $this->lead();
        $row = $this->asTenant($this->t['tenant'], fn () => Appointment::create(['lead_id' => $lead->id, 'scheduled_at' => now()->addDay(), 'status' => 'scheduled']));

        $this->api()->getJson('/api/v1/appointments?bucket=upcoming')->assertOk()->assertJsonPath('data.0.status', 'confirmed');
        $this->api()->patchJson("/api/v1/appointments/{$row->uuid}", ['status' => 'completed'])->assertOk();
    }

    public function test_a_unit_is_held_once_released_on_cancel_and_sold_on_conversion(): void
    {
        $a = $this->lead();
        $b = $this->lead();

        $first = $this->api()->postJson('/api/v1/reservations', ['lead_id' => $a->uuid, 'property_id' => $this->unit->uuid, 'amount' => 5000])->assertCreated()->json('data.reservation');
        $this->assertSame('pending', $first['status']);
        $this->assertSame(['confirm', 'cancel', 'convert'], $first['moves']);
        $this->assertSame('reserved', $this->asTenant($this->t['tenant'], fn () => $this->unit->fresh()->status));

        // Nobody else can promise the same unit.
        $this->api()->postJson('/api/v1/reservations', ['lead_id' => $b->uuid, 'property_id' => $this->unit->uuid])->assertStatus(422);

        $this->api()->postJson("/api/v1/reservations/{$first['id']}/cancel", ['reason' => 'غيّر رأيه'])->assertOk()->assertJsonPath('data.reservation.status', 'cancelled');
        $this->assertSame('available', $this->asTenant($this->t['tenant'], fn () => $this->unit->fresh()->status));

        $second = $this->api()->postJson('/api/v1/reservations', ['lead_id' => $b->uuid, 'property_id' => $this->unit->uuid])->assertCreated()->json('data.reservation.id');
        $this->api()->postJson("/api/v1/reservations/{$second}/confirm")->assertOk()->assertJsonPath('data.reservation.status', 'confirmed');
        $this->api()->postJson("/api/v1/reservations/{$second}/convert")->assertOk()->assertJsonPath('data.reservation.status', 'converted');

        $this->asTenant($this->t['tenant'], function () use ($b) {
            $this->assertSame('sold', $this->unit->fresh()->status);
            $this->assertSame('won', $b->fresh()->status->value);
            $this->assertSame(90_000_000, $b->fresh()->deal_value_minor);
        });

        // Ended is ended.
        $this->api()->postJson("/api/v1/reservations/{$second}/cancel")->assertStatus(422);
    }

    public function test_an_unconfirmed_hold_lapses_and_the_unit_goes_back_on_the_market(): void
    {
        $lead = $this->lead();
        $id = $this->api()->postJson('/api/v1/reservations', ['lead_id' => $lead->uuid, 'property_id' => $this->unit->uuid])->assertCreated()->json('data.reservation.id');
        $confirmed = $this->lead();
        $keep = $this->asTenant($this->t['tenant'], fn () => Property::create(['project_id' => $this->project->id, 'code' => 'A-13', 'property_type' => 'apartment', 'title' => 'شقة 13']));
        $kept = $this->api()->postJson('/api/v1/reservations', ['lead_id' => $confirmed->uuid, 'property_id' => $keep->uuid])->assertCreated()->json('data.reservation.id');
        $this->api()->postJson("/api/v1/reservations/{$kept}/confirm")->assertOk();

        DB::table('reservations')->update(['expires_at' => now()->subHour()]);
        $this->assertSame(0, Artisan::call('leadflow:expire-reservations'));

        $this->asTenant($this->t['tenant'], function () use ($id, $kept, $keep) {
            $this->assertSame('expired', Reservation::where('uuid', $id)->value('status'));
            $this->assertSame('available', $this->unit->fresh()->status);
            // A confirmed one does not lapse.
            $this->assertSame('confirmed', Reservation::where('uuid', $kept)->value('status'));
            $this->assertSame('reserved', $keep->fresh()->status);
        });
    }

    public function test_a_rep_sees_only_their_own_visits_and_reservations(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $mine = $this->lead(['assigned_to' => $rep->id]);
        $theirs = $this->lead(['assigned_to' => $this->t['admin']->id]);
        $this->asTenant($this->t['tenant'], function () use ($mine, $theirs, $rep) {
            Appointment::create(['lead_id' => $mine->id, 'assigned_to' => $rep->id, 'scheduled_at' => now()->addDay()]);
            Appointment::create(['lead_id' => $theirs->id, 'assigned_to' => $this->t['admin']->id, 'scheduled_at' => now()->addDay()]);
            app(ReservationService::class)->create($mine, $rep);
            app(ReservationService::class)->create($theirs, $this->t['admin']);
        });

        $this->assertCount(1, $this->actingAs($rep)->getJson('/api/v1/appointments')->assertOk()->json('data'));
        $this->assertCount(1, $this->actingAs($rep)->getJson('/api/v1/reservations')->assertOk()->json('data'));
        $this->assertCount(2, $this->api()->getJson('/api/v1/reservations')->assertOk()->json('data'));
    }

    public function test_the_funnel_counts_every_lead_at_each_step_it_reached(): void
    {
        $this->lead();                                                                    // lead
        $this->lead(['qualification_status' => 'qualified']);                              // qualified
        $this->asTenant($this->t['tenant'], fn () => DB::table('leads')->where('id', $this->lead()->id)->update(['interest_level' => 'hot']));  // interested
        $saw = $this->lead();
        $this->api()->postJson('/api/v1/appointments/'.$this->visit($saw).'/transition', ['to' => 'completed']);       // visit
        $held = $this->lead();
        $this->api()->postJson('/api/v1/reservations', ['lead_id' => $held->uuid]);                                    // reservation
        $this->lead(['status' => 'won']);                                                                              // won

        $steps = $this->asTenant($this->t['tenant'], fn () => LeadFunnel::counts(Lead::query()))['steps'];

        $this->assertSame(['leads' => 6, 'qualified' => 5, 'interested' => 4, 'visit' => 3, 'reservation' => 2, 'won' => 1], $steps);
        $manager = $this->api()->getJson('/api/v1/dashboard/manager?days=7')->assertOk()->json('data.re_funnel.steps');
        $this->assertSame($steps, $manager);
    }
}
