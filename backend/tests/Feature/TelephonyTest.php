<?php

namespace Tests\Feature;

use App\Domain\Calls\Providers\TwilioCallProvider;
use App\Enums\Role;
use App\Models\Call;
use App\Models\FollowUp;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A real telephony provider behind the abstraction, and the fallback to the
 * rep dialling by hand.
 *
 * The abstraction existed with one implementation that places no calls, so the
 * claim "a provider can be connected later" had never been exercised by
 * anything. These are the first tests that make it true: a call is placed
 * through a provider, its state moves on the provider's word, and when the
 * provider cannot help, the button still works.
 */
class TelephonyTest extends TestCase
{
    use RefreshDatabase;

    private const SID = 'AC0123456789abcdef0123456789abcdef';

    private const TOKEN = 'auth-token-0123456789abcdef';

    private array $t;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Voice Co');

        $this->t['admin']->forceFill(['phone' => '0551000001'])->save();

        $this->lead = $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => 'سلمان القحطاني',
            'phone' => '0551234567',
            'phone_normalized' => '+966551234567',
            'status' => 'new',
            'assigned_to' => $this->t['admin']->id,
        ]));
    }

    private function connect(): Integration
    {
        return $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'twilio_voice',
            'name' => 'Twilio Voice',
            'status' => Integration::CONNECTED,
            'config' => ['from_number' => '+966110000000'],
            'credentials' => ['account_sid' => self::SID, 'auth_token' => self::TOKEN],
            'is_active' => true,
        ]));
    }

    private function place(): array
    {
        return $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$this->lead->uuid}/contact", ['channel' => 'call'])
            ->assertOk()
            ->json('data.call');
    }

    private function sign(Integration $integration, array $params): string
    {
        $provider = app(TwilioCallProvider::class);
        ksort($params);

        $data = $provider->statusUrl($integration);

        foreach ($params as $key => $value) {
            $data .= $key.$value;
        }

        return base64_encode(hash_hmac('sha1', $data, self::TOKEN, true));
    }

    private function report(Integration $integration, array $params, ?string $signature = null)
    {
        return $this->post(
            "/api/v1/integrations/calls/twilio/{$integration->uuid}/status",
            $params,
            ['X-Twilio-Signature' => $signature ?? $this->sign($integration, $params)],
        );
    }

    // ------------------------------------------------------------- placing

    public function test_with_no_telephony_the_rep_dials_and_the_call_is_still_recorded(): void
    {
        $call = $this->place();

        $this->assertSame('manual', $call['mode']);
        $this->assertNull($call['fallback_reason']);
    }

    public function test_a_connected_workspace_has_the_provider_ring_the_rep(): void
    {
        $this->connect();

        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA111', 'status' => 'queued'], 201)]);

        $call = $this->place();

        $this->assertSame('bridge', $call['mode']);

        Http::assertSent(function ($request) {
            $body = $request->body();

            return str_contains($request->url(), '/Accounts/'.self::SID.'/Calls.json')
                // The rep's phone rings first, and the lead only ever hears a person.
                && str_contains($body, 'To=%2B966551000001')
                && str_contains($body, 'From=%2B966110000000')
                && str_contains($body, '%3CNumber%3E%2B966551234567%3C%2FNumber%3E')
                // A list is repeated keys, not PHP's bracketed form.
                && substr_count($body, 'StatusCallbackEvent=') === 4
                && ! str_contains($body, 'StatusCallbackEvent%5B');
        });

        $stored = Call::withoutGlobalScopes()->where('lead_id', $this->lead->id)->first();
        $this->assertSame('CA111', $stored->external_id);
        $this->assertSame('twilio', $stored->provider);
    }

    /**
     * The button must keep working when the workspace's own telephony cannot.
     *
     * A rep with no number on their profile, a provider that refuses, an
     * outage — none of those is a reason for a dead button. The call is made by
     * hand and the reason is returned so the screen can say why.
     */
    public function test_a_refusal_falls_back_to_the_rep_dialling_and_says_why(): void
    {
        $this->connect();

        Http::fake(['api.twilio.com/*' => Http::response(['code' => 21215, 'message' => 'Geo permission'], 400)]);

        $call = $this->place();

        $this->assertSame('manual', $call['mode']);
        $this->assertSame('refused', $call['fallback_reason']);

        $this->assertSame(
            'manual',
            Call::withoutGlobalScopes()->where('lead_id', $this->lead->id)->first()->provider,
        );
    }

    public function test_a_rep_with_no_phone_falls_back_rather_than_failing(): void
    {
        $this->connect();
        $this->t['admin']->forceFill(['phone' => null])->save();

        Http::fake();

        $call = $this->place();

        $this->assertSame('manual', $call['mode']);
        $this->assertSame('rep_has_no_phone', $call['fallback_reason']);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------- the webhook

    public function test_a_signed_status_moves_the_call_and_records_the_measured_duration(): void
    {
        $integration = $this->connect();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA222'], 201)]);
        $this->place();

        $params = ['CallSid' => 'CA222', 'CallStatus' => 'completed', 'CallDuration' => '187'];

        $this->report($integration, $params)->assertNoContent();

        $call = Call::withoutGlobalScopes()->where('external_id', 'CA222')->first();

        $this->assertSame(Call::COMPLETED, $call->status);
        $this->assertSame(187, $call->duration_seconds);
        $this->assertTrue($call->wasMeasured());
        $this->assertNotNull($call->ended_at);
    }

    public function test_an_unsigned_or_forged_status_changes_nothing(): void
    {
        $integration = $this->connect();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA333'], 201)]);
        $this->place();

        $params = ['CallSid' => 'CA333', 'CallStatus' => 'completed', 'CallDuration' => '999'];

        $this->report($integration, $params, 'AAAA')->assertForbidden();
        $this->report($integration, $params, '')->assertForbidden();

        $call = Call::withoutGlobalScopes()->where('external_id', 'CA333')->first();

        $this->assertSame(Call::INITIATED, $call->status);
        $this->assertNull($call->duration_seconds);
    }

    /** A signature valid for another workspace's token is not valid here. */
    public function test_one_workspaces_signature_does_not_work_on_another(): void
    {
        $integration = $this->connect();

        $other = $this->provisionTenant('Other Voice Co');
        $this->asTenant($other['tenant'], fn () => Integration::create([
            'provider' => 'twilio_voice',
            'name' => 'Twilio Voice',
            'status' => Integration::CONNECTED,
            'config' => ['from_number' => '+966110000001'],
            'credentials' => ['account_sid' => self::SID, 'auth_token' => 'a-different-token-0123456789'],
            'is_active' => true,
        ]));

        $params = ['CallSid' => 'CA444', 'CallStatus' => 'completed'];

        // Signed with this workspace's token, posted to the other's URL.
        $theirs = Integration::withoutGlobalScopes()
            ->where('tenant_id', $other['tenant']->id)->where('provider', 'twilio_voice')->first();

        $this->report($theirs, $params, $this->sign($integration, $params))->assertForbidden();
    }

    /**
     * Twilio does not promise order.
     *
     * A late "ringing" that arrives after "completed" must not reopen a call
     * that is over, or zero out the duration it just measured.
     */
    public function test_a_late_status_cannot_undo_a_finished_call(): void
    {
        $integration = $this->connect();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA555'], 201)]);
        $this->place();

        $this->report($integration, ['CallSid' => 'CA555', 'CallStatus' => 'completed', 'CallDuration' => '60']);
        $this->report($integration, ['CallSid' => 'CA555', 'CallStatus' => 'ringing']);

        $call = Call::withoutGlobalScopes()->where('external_id', 'CA555')->first();

        $this->assertSame(Call::COMPLETED, $call->status);
        $this->assertSame(60, $call->duration_seconds);
    }

    public function test_an_unanswered_call_books_the_follow_up_the_rep_would_have(): void
    {
        $integration = $this->connect();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA666'], 201)]);
        $this->place();

        // Asserted, because a webhook that 500s is retried by Twilio and its
        // failure would otherwise surface only as a missing follow-up.
        $this->report($integration, ['CallSid' => 'CA666', 'CallStatus' => 'no-answer'])->assertNoContent();

        $call = Call::withoutGlobalScopes()->where('external_id', 'CA666')->first();

        $this->assertSame(Call::NO_ANSWER, $call->status);
        $this->assertSame('no_answer', $call->outcome);

        $this->assertTrue(
            FollowUp::withoutGlobalScopes()->where('lead_id', $this->lead->id)->where('status', 'pending')->exists(),
            'an unanswered call left nothing to do next',
        );

        // And it says it was the telephony that noticed, not the work queue.
        $this->assertTrue(
            LeadActivity::withoutGlobalScopes()
                ->where('lead_id', $this->lead->id)
                ->get()
                ->contains(fn ($activity) => ($activity->meta['via'] ?? null) === 'telephony'),
        );
    }

    /** A repeated terminal status must not book a second follow-up. */
    public function test_a_repeated_no_answer_is_idempotent(): void
    {
        $integration = $this->connect();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA777'], 201)]);
        $this->place();

        $params = ['CallSid' => 'CA777', 'CallStatus' => 'no-answer'];

        $this->report($integration, $params)->assertNoContent();
        $this->report($integration, $params)->assertNoContent();

        $this->assertSame(
            1,
            FollowUp::withoutGlobalScopes()->where('lead_id', $this->lead->id)->count(),
        );
    }

    // ---------------------------------------------------------- settings

    public function test_saved_credentials_are_never_read_back(): void
    {
        $response = $this->actingAs($this->t['admin'])->putJson('/api/v1/integrations/calls', [
            'account_sid' => self::SID,
            'auth_token' => self::TOKEN,
            'from_number' => '+966110000000',
        ])->assertOk();

        $body = $response->getContent();

        $this->assertStringNotContainsString(self::TOKEN, $body);
        $this->assertStringNotContainsString(self::SID, $body);
        $response->assertJsonPath('data.calls.has_token', true);
        // Saved is not connected: only a successful test earns that word.
        $response->assertJsonPath('data.calls.status', Integration::CONFIGURATION_REQUIRED);
    }

    public function test_a_test_call_to_twilio_is_what_marks_it_connected(): void
    {
        $this->actingAs($this->t['admin'])->putJson('/api/v1/integrations/calls', [
            'account_sid' => self::SID,
            'auth_token' => self::TOKEN,
            'from_number' => '+966110000000',
        ]);

        Http::fake(['api.twilio.com/*' => Http::response(['status' => 'active', 'friendly_name' => 'Voice Co'])]);

        $this->actingAs($this->t['admin'])->postJson('/api/v1/integrations/calls/test')
            ->assertOk()
            ->assertJsonPath('data.calls.status', Integration::CONNECTED);
    }

    public function test_a_rep_cannot_connect_the_workspaces_telephony(): void
    {
        $rep = User::factory()->create([
            'tenant_id' => $this->t['tenant']->id,
            'role' => Role::Sales,
        ]);

        $this->actingAs($rep)->putJson('/api/v1/integrations/calls', [
            'account_sid' => self::SID,
            'auth_token' => self::TOKEN,
            'from_number' => '+966110000000',
        ])->assertForbidden();
    }
}
