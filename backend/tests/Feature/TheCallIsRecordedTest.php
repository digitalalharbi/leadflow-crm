<?php

namespace Tests\Feature;

use App\Domain\Calls\CallProviderRegistry;
use App\Enums\ActivityType;
use App\Models\Call;
use App\Models\Lead;
use App\Models\LeadActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A call is a record, not only a line in a timeline.
 *
 * The timeline answers "what happened to this lead". This answers "how many
 * calls did we make, to whom, and what came of them" — a manager's question
 * and a report's, and an activity row cannot carry a duration or an outcome
 * without becoming something else.
 *
 * The honesty rule runs through all of it: nothing is watching a rep's phone,
 * so مهتم knows only that the button was pressed and what the rep said
 * afterwards. A status that advanced on its own, or a duration nobody
 * measured, would be a guess printed as a fact — and reports are what people
 * make decisions from.
 */
class TheCallIsRecordedTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Call Co');

        $this->lead = $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => 'عميل',
            'phone_normalized' => '+966551234567',
            'status' => 'new',
            'assigned_to' => $this->t['admin']->id,
        ]));
    }

    private function press(string $channel = 'call')
    {
        return $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$this->lead->uuid}/contact", ['channel' => $channel]);
    }

    public function test_pressing_call_records_a_call(): void
    {
        $response = $this->press()->assertOk();

        $call = Call::withoutGlobalScopes()->firstOrFail();

        $this->assertSame($this->lead->id, $call->lead_id);
        $this->assertSame($this->t['admin']->id, $call->user_id);
        $this->assertSame('manual', $call->provider);
        $this->assertSame($call->uuid, $response->json('data.call.id'));
    }

    /**
     * The status stays where it is until a person says otherwise. Nothing is
     * watching the rep's phone.
     */
    public function test_a_manual_call_does_not_pretend_to_know_how_it_went(): void
    {
        $this->press()->assertOk();

        $call = Call::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(Call::INITIATED, $call->status);
        $this->assertNull($call->duration_seconds);
        $this->assertNull($call->ended_at);
        $this->assertFalse($call->wasMeasured());
    }

    /** The timeline still gets its line; the two records answer different questions. */
    public function test_the_timeline_still_shows_it(): void
    {
        $this->press()->assertOk();

        $this->assertSame(1, LeadActivity::withoutGlobalScopes()
            ->where('type', ActivityType::Called->value)->count());
    }

    public function test_opening_whatsapp_is_not_a_call(): void
    {
        $this->press('whatsapp')->assertOk();

        $this->assertSame(0, Call::withoutGlobalScopes()->count());
    }

    public function test_the_rep_says_what_happened(): void
    {
        $this->press()->assertOk();
        $call = Call::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$this->lead->uuid}/calls/{$call->uuid}", [
                'status' => 'answered',
                'outcome' => 'interested',
            ])
            ->assertOk()
            ->assertJsonPath('data.call.status', 'answered')
            ->assertJsonPath('data.call.measured', false);

        $this->assertNotNull($call->fresh()->ended_at);
    }

    /**
     * A duration is accepted when somebody supplies one and is never invented.
     * `measured` stays false for a manual call however it was filled in: the
     * number came from a person, not from a telephone system.
     */
    public function test_a_duration_is_taken_but_not_called_measured(): void
    {
        $this->press()->assertOk();
        $call = Call::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$this->lead->uuid}/calls/{$call->uuid}", [
                'status' => 'completed',
                'duration_seconds' => 95,
            ])
            ->assertOk()
            ->assertJsonPath('data.call.duration_seconds', 95)
            ->assertJsonPath('data.call.measured', false);
    }

    public function test_a_status_nobody_recognises_is_refused(): void
    {
        $this->press()->assertOk();
        $call = Call::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$this->lead->uuid}/calls/{$call->uuid}", ['status' => 'telepathy'])
            ->assertStatus(422);
    }

    public function test_one_workspace_cannot_record_against_anothers_call(): void
    {
        $this->press()->assertOk();
        $call = Call::withoutGlobalScopes()->firstOrFail();

        $other = $this->provisionTenant('Somebody Else');

        $this->actingAs($other['admin'])
            ->postJson("/api/v1/leads/{$this->lead->uuid}/calls/{$call->uuid}", ['status' => 'answered'])
            ->assertNotFound();
    }

    /**
     * The default provider places no calls and says so. A product that reported
     * connected calls and invented durations would be putting fiction into the
     * reports people make decisions from.
     */
    public function test_the_default_provider_admits_it_cannot_place_a_call(): void
    {
        $provider = app(CallProviderRegistry::class)->forTenant();

        $this->assertSame('manual', $provider->key());
        $this->assertFalse($provider->canPlace());
    }

    public function test_there_is_no_mock_provider_registered(): void
    {
        // A fake that answers calls would be indistinguishable from a real one
        // in every report it touched.
        //
        // An allow-list rather than "manual only", now that a real provider is
        // registered: adding another is a decision somebody makes by editing
        // this line, not something that can arrive unnoticed under a name like
        // `demo`. `twilio` is a real adapter — it places a call only for a
        // workspace that gave it credentials, and reports nothing it was not
        // told by Twilio.
        $keys = app(CallProviderRegistry::class)->keys();

        sort($keys);

        $this->assertSame(['manual', 'twilio'], $keys);

        foreach ($keys as $key) {
            $this->assertDoesNotMatchRegularExpression('/mock|fake|demo|stub/i', $key);
        }
    }
}
