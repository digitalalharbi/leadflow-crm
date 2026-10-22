<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Call;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The lead screen is the place a rep continues from, so one request has to
 * carry everything they continue with: what to do next, the calls made, the
 * meetings booked.
 */
class LeadDetailPictureTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_lead_carries_its_next_action_calls_and_appointments(): void
    {
        $t = $this->provisionTenant('Detail Co');

        $lead = $this->asTenant($t['tenant'], function () use ($t) {
            $lead = Lead::create(['full_name' => 'عميل', 'phone_normalized' => '+966500000777', 'status' => 'contacted']);
            Call::create(['lead_id' => $lead->id, 'user_id' => $t['admin']->id, 'provider' => 'manual', 'direction' => 'outbound', 'status' => 'completed', 'duration_seconds' => 95, 'started_at' => now()]);
            Appointment::create(['lead_id' => $lead->id, 'assigned_to' => $t['admin']->id, 'created_by' => $t['admin']->id, 'scheduled_at' => now()->addDay(), 'duration_minutes' => 30]);

            return $lead;
        });

        $data = $this->actingAs($t['admin'])->getJson('/api/v1/leads/'.$lead->uuid)->assertOk()->json('data');

        $this->assertArrayHasKey('action', $data['next_action']);
        $this->assertArrayHasKey('urgency', $data['next_action']);
        $this->assertCount(1, $data['calls']);
        $this->assertSame(95, $data['calls'][0]['duration_seconds']);
        $this->assertSame($t['admin']->name, $data['calls'][0]['by']);
        $this->assertCount(1, $data['appointments']);
        $this->assertSame(30, $data['appointments'][0]['duration_minutes']);
        $this->assertArrayHasKey('key', $data['lead']['classification']);
    }

    public function test_another_tenants_calls_never_appear(): void
    {
        $a = $this->provisionTenant('Tenant A');
        $b = $this->provisionTenant('Tenant B', ['admin_email' => 'b@example.test']);

        $this->asTenant($b['tenant'], function () use ($b) {
            $lead = Lead::create(['full_name' => 'عميل ب', 'phone_normalized' => '+966500000888', 'status' => 'new']);
            Call::create(['lead_id' => $lead->id, 'user_id' => $b['admin']->id, 'provider' => 'manual', 'direction' => 'outbound', 'status' => 'completed', 'started_at' => now()]);
        });

        $leadA = $this->asTenant($a['tenant'], fn () => Lead::create(['full_name' => 'عميل أ', 'phone_normalized' => '+966500000999', 'status' => 'new']));

        $this->assertCount(0, $this->actingAs($a['admin'])->getJson('/api/v1/leads/'.$leadA->uuid)->json('data.calls'));
    }

    /**
     * The phone's lead screen says how long the customer has waited, with the same clock the work queue
     * shows — so the two cannot disagree. A lead with no clock (not interested enough to have a window)
     * says nothing rather than inventing one.
     */
    public function test_the_lead_carries_the_reply_clock_the_work_queue_shows(): void
    {
        $t = $this->provisionTenant('Clock Co');

        [$hot, $cold] = $this->asTenant($t['tenant'], function () use ($t) {
            $make = fn (string $phone, string $level, $since) => tap(Lead::create([
                'full_name' => 'عميل '.$level, 'phone_normalized' => $phone, 'status' => 'contacted', 'assigned_to' => $t['admin']->id,
            ]), fn (Lead $lead) => DB::table('leads')->where('id', $lead->id)->update(['interest_level' => $level, 'interest_level_changed_at' => $since]));

            // A hot lead has fifteen minutes; this one has waited twenty.
            return [$make('+966500000901', 'hot', now()->subMinutes(20)), $make('+966500000902', 'cold', now()->subMinutes(20))];
        });

        $clock = $this->actingAs($t['admin'])->getJson('/api/v1/leads/'.$hot->uuid)->assertOk()->json('data.sla');

        $this->assertSame('hot', $clock['level']);
        $this->assertSame('breached', $clock['state']);
        $this->assertSame(15, $clock['target_minutes']);
        $this->assertLessThan(0, $clock['minutes_left']);

        $this->actingAs($t['admin'])->getJson('/api/v1/leads/'.$cold->uuid)->assertOk()->assertJsonPath('data.sla', null);
    }
}
