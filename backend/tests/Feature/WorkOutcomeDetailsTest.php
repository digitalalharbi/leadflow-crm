<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LostReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The phone's outcome sheet records what actually happened: a sale with its value, a loss with its reason, a booked
 * visit as a real visit at the time agreed, and the next call at the time the rep chose.
 */
class WorkOutcomeDetailsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private $rep;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alpha Realty');
        $this->rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $this->lead = $this->asTenant($this->t['tenant'], fn () => Lead::create(['full_name' => 'Customer', 'phone' => '0501112222', 'phone_normalized' => '+966501112222', 'assigned_to' => $this->rep->id]));
    }

    private function outcome(array $payload)
    {
        return $this->actingAs($this->rep)->postJson('/api/v1/work/outcome', ['lead_id' => $this->lead->uuid] + $payload);
    }

    public function test_a_sale_carries_its_value_into_revenue(): void
    {
        $this->outcome(['outcome' => 'won', 'deal_value' => 850000])->assertOk();

        $lead = $this->lead->fresh();
        $this->assertSame('won', $lead->status->value);
        $this->assertSame(85000000, (int) $lead->deal_value_minor);
    }

    public function test_a_loss_carries_its_reason(): void
    {
        $reason = $this->asTenant($this->t['tenant'], fn () => LostReason::first());

        $this->outcome(['outcome' => 'not_interested', 'lost_reason_id' => $reason->uuid])->assertOk();

        $this->assertSame($reason->id, $this->lead->fresh()->lost_reason_id);
    }

    public function test_another_companys_reason_is_refused(): void
    {
        $other = $this->provisionTenant('Beta Realty');
        $foreign = $this->asTenant($other['tenant'], fn () => LostReason::first());

        $this->outcome(['outcome' => 'lost', 'lost_reason_id' => $foreign->uuid])->assertNotFound();
        $this->assertNotSame('lost', $this->lead->fresh()->status->value);
    }

    public function test_a_booked_visit_is_a_real_visit_at_the_agreed_time(): void
    {
        $at = now()->addDays(2)->setTime(17, 30)->startOfMinute();

        $this->outcome(['outcome' => 'meeting_booked', 'appointment_at' => $at->toIso8601String()])->assertOk();

        $visit = $this->asTenant($this->t['tenant'], fn () => Appointment::where('lead_id', $this->lead->id)->first());
        $this->assertNotNull($visit);
        $this->assertTrue($visit->scheduled_at->equalTo($at));
        $this->assertSame('appointment', $this->lead->fresh()->status->value);

        $this->outcome(['outcome' => 'meeting_booked', 'appointment_at' => now()->subHour()->toIso8601String()])->assertUnprocessable();
    }

    public function test_the_next_call_is_at_the_time_the_rep_chose_and_a_second_choice_moves_it(): void
    {
        $first = now()->addDays(3)->setTime(10, 0)->startOfMinute();
        $this->outcome(['outcome' => 'call_later', 'follow_up_at' => $first->toIso8601String()])->assertOk();

        $pending = $this->asTenant($this->t['tenant'], fn () => FollowUp::where('lead_id', $this->lead->id)->where('status', FollowUp::PENDING)->get());
        $this->assertCount(1, $pending);
        $this->assertTrue($pending->first()->due_at->equalTo($first));

        $second = now()->addDays(5)->setTime(9, 0)->startOfMinute();
        $this->outcome(['outcome' => 'no_answer', 'follow_up_at' => $second->toIso8601String()])->assertOk();

        $pending = $this->asTenant($this->t['tenant'], fn () => FollowUp::where('lead_id', $this->lead->id)->where('status', FollowUp::PENDING)->get());
        $this->assertCount(1, $pending);
        $this->assertTrue($pending->first()->due_at->equalTo($second));
    }
}
