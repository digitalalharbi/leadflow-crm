<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Meetings, and the stage move that must come with them.
 *
 * The behaviour worth protecting is that booking a meeting is enough: asking a
 * rep to record the meeting and then separately drag the card is the manual
 * step people forget, and the one that leaves the pipeline disagreeing with the
 * calendar.
 */
class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Meetings Co');
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل الموعد',
            'phone_normalized' => '+9665000'.self::uniqueDigits(5),
            'status' => 'qualified',
        ], $attributes)));
    }

    public function test_booking_a_meeting_moves_the_lead_to_the_appointment_stage(): void
    {
        $lead = $this->lead();

        $this->actingAs($this->t['admin'])->postJson('/api/v1/appointments', [
            'lead_id' => $lead->uuid,
            'scheduled_at' => now()->addDays(2)->toIso8601String(),
            'location' => 'المكتب',
        ])->assertCreated();

        $this->assertSame('appointment', $lead->fresh()->status->value);
    }

    /** A handover call booked after the sale must not drag the lead backwards. */
    public function test_booking_never_moves_a_lead_backwards(): void
    {
        $lead = $this->lead(['status' => 'won']);

        $this->actingAs($this->t['admin'])->postJson('/api/v1/appointments', [
            'lead_id' => $lead->uuid,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated();

        $this->assertSame('won', $lead->fresh()->status->value);
    }

    public function test_a_meeting_cannot_be_booked_in_the_past(): void
    {
        $lead = $this->lead();

        $this->actingAs($this->t['admin'])->postJson('/api/v1/appointments', [
            'lead_id' => $lead->uuid,
            'scheduled_at' => now()->subHour()->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_the_meeting_is_assigned_to_whoever_owns_the_lead(): void
    {
        $rep = $this->makeUser($this->t['tenant']);
        $lead = $this->lead();
        $lead->forceFill(['assigned_to' => $rep->id])->saveQuietly();

        $this->actingAs($this->t['admin'])->postJson('/api/v1/appointments', [
            'lead_id' => $lead->uuid,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated();

        $this->asTenant($this->t['tenant'], function () use ($rep) {
            $this->assertSame($rep->id, Appointment::first()->assigned_to);
        });
    }

    /**
     * The set a manager actually needs: the meeting time has passed and nobody
     * said what happened. Either a sale went unrecorded or a customer was not
     * met, and both are invisible without this.
     */
    public function test_a_past_meeting_with_no_outcome_is_listed_as_awaiting(): void
    {
        $lead = $this->lead();

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            Appointment::create([
                'lead_id' => $lead->id,
                'assigned_to' => $this->t['admin']->id,
                'scheduled_at' => now()->subHours(3),
                'status' => 'scheduled',
            ]);
            Appointment::create([
                'lead_id' => $lead->id,
                'assigned_to' => $this->t['admin']->id,
                'scheduled_at' => now()->addDay(),
                'status' => 'scheduled',
            ]);
        });

        $awaiting = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/appointments?bucket=awaiting_outcome')
            ->assertOk()->json('data');

        $this->assertCount(1, $awaiting);
        $this->assertTrue($awaiting[0]['is_past']);
    }

    public function test_completing_a_meeting_records_when(): void
    {
        $lead = $this->lead();

        $appointment = $this->asTenant($this->t['tenant'], fn () => Appointment::create([
            'lead_id' => $lead->id,
            'assigned_to' => $this->t['admin']->id,
            'scheduled_at' => now()->subHour(),
        ]));

        $this->actingAs($this->t['admin'])
            ->patchJson("/api/v1/appointments/{$appointment->uuid}", [
                'status' => 'completed',
                'outcome_note' => 'حضر وطلب عرضًا',
            ])->assertOk();

        $this->assertNotNull($appointment->fresh()->completed_at);
    }

    /** Another workspace's meeting is not visible, by id or by list. */
    public function test_meetings_do_not_cross_tenants(): void
    {
        $other = $this->provisionTenant('Someone Else');

        $theirLead = $this->asTenant($other['tenant'], fn () => Lead::create([
            'full_name' => 'سر', 'phone_normalized' => '+966500111222', 'status' => 'qualified',
        ]));

        $theirs = $this->asTenant($other['tenant'], fn () => Appointment::create([
            'lead_id' => $theirLead->id,
            'scheduled_at' => now()->addDay(),
        ]));

        $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/appointments')->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->t['admin'])
            ->patchJson("/api/v1/appointments/{$theirs->uuid}", ['status' => 'cancelled'])
            ->assertNotFound();
    }
}
