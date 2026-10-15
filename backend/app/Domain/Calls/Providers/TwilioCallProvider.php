<?php

namespace App\Domain\Calls\Providers;

use App\Domain\Calls\Contracts\CallProviderInterface;
use App\Domain\Calls\Exceptions\CallCannotBePlaced;
use App\Enums\IntegrationProvider;
use App\Models\Call;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\PhoneNormalizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A call bridged by Twilio: it rings the rep, and on answer connects the lead.
 *
 * The rep's phone rings first, deliberately. A customer who picks up to silence
 * while a rep is found is a customer who hangs up, and the workspace's number
 * is the one that gets the complaint. Bridging from the rep's side means the
 * lead only ever hears a person.
 *
 * Credentials are the workspace's own, held on its integration row — never the
 * platform's. Nothing here is shared between tenants, and nothing here invents
 * a status: every state and every duration is what Twilio reported.
 */
class TwilioCallProvider implements CallProviderInterface
{
    private const API = 'https://api.twilio.com/2010-04-01';

    public function key(): string
    {
        return 'twilio';
    }

    public function integration(): ?Integration
    {
        return Integration::where('provider', IntegrationProvider::TwilioVoice->value)->first();
    }

    public function canPlace(): bool
    {
        $integration = $this->integration();

        return $integration !== null
            && $integration->is_active !== false
            && filled($integration->credentials['account_sid'] ?? null)
            && filled($integration->credentials['auth_token'] ?? null)
            && filled($integration->config['from_number'] ?? null);
    }

    public function place(Lead $lead, User $by): Call
    {
        if (! $this->canPlace()) {
            throw new CallCannotBePlaced('not_configured');
        }

        $integration = $this->integration();

        $from = $this->digits((string) $integration->config['from_number']);
        $rep = $this->digits((string) $by->phone);
        $to = $this->digits((string) ($lead->phone_normalized ?: $lead->phone));

        if ($rep === '') {
            // The rep's own number is where the bridge starts. Without one there
            // is nowhere to ring, and that is theirs to fix on their profile.
            throw new CallCannotBePlaced('rep_has_no_phone');
        }

        if ($to === '') {
            throw new CallCannotBePlaced('lead_has_no_phone');
        }

        $sid = (string) $integration->credentials['account_sid'];

        $params = [
            'To' => $rep,
            'From' => $from,
            // Inline, so there is no TwiML endpoint of ours to keep reachable and
            // nothing public that decides who gets dialled.
            'Twiml' => '<Response><Dial callerId="'.$from.'"><Number>'.$to.'</Number></Dial></Response>',
            'StatusCallback' => $this->statusUrl($integration),
            'StatusCallbackMethod' => 'POST',
        ];

        // Repeated keys, which is how Twilio takes a list — and not the
        // `Key[0]=` form PHP's own encoder produces for an array.
        $body = http_build_query($params);

        foreach (['initiated', 'ringing', 'answered', 'completed'] as $event) {
            $body .= '&StatusCallbackEvent='.$event;
        }

        try {
            $response = Http::withBasicAuth($sid, (string) $integration->credentials['auth_token'])
                ->timeout(15)
                ->withBody($body, 'application/x-www-form-urlencoded')
                ->post(self::API."/Accounts/{$sid}/Calls.json");
        } catch (\Throwable $e) {
            Log::warning('twilio.place_transport_failed', [
                'integration' => $integration->uuid,
                'exception' => $e::class,
            ]);

            throw new CallCannotBePlaced('unreachable');
        }

        if ($response->failed() || blank($response->json('sid'))) {
            Log::warning('twilio.place_refused', [
                'integration' => $integration->uuid,
                'status' => $response->status(),
                'code' => $response->json('code'),
            ]);

            $integration->forceFill(['last_error' => (string) ($response->json('message') ?: 'Twilio refused the call.')])->save();

            throw new CallCannotBePlaced('refused', (string) $response->json('message'));
        }

        return Call::create([
            'lead_id' => $lead->id,
            'user_id' => $by->id,
            'provider' => $this->key(),
            'external_id' => (string) $response->json('sid'),
            'direction' => 'outbound',
            'status' => Call::INITIATED,
            'started_at' => now(),
        ]);
    }

    /** Where Twilio reports back. The integration's own uuid identifies the workspace. */
    public function statusUrl(Integration $integration): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/integrations/calls/twilio/'.$integration->uuid.'/status';
    }

    /**
     * Twilio's request signature: HMAC-SHA1 over the URL and the sorted POST
     * fields, keyed with the auth token, base64 encoded.
     *
     * Over the URL we handed Twilio, not whatever URL this request appears to
     * have arrived on: behind a proxy those differ, and a signature check that
     * depends on the proxy's rewriting fails open or closed at random.
     *
     * @param  array<string,mixed>  $params
     */
    public function validSignature(Integration $integration, array $params, ?string $signature): bool
    {
        $token = $integration->credentials['auth_token'] ?? null;

        if (blank($token) || blank($signature)) {
            return false;
        }

        ksort($params);

        $data = $this->statusUrl($integration);

        foreach ($params as $key => $value) {
            $data .= $key.(is_array($value) ? implode('', $value) : $value);
        }

        return hash_equals(base64_encode(hash_hmac('sha1', $data, (string) $token, true)), $signature);
    }

    /**
     * Twilio's vocabulary, folded into ours.
     *
     * A call the rep never picked up and one the lead never picked up are both
     * `no_answer` here, which is the honest answer to "did a conversation
     * happen" and the only one a single leg's status can give.
     */
    public static function mapStatus(string $twilio): ?string
    {
        return match ($twilio) {
            'queued', 'initiated' => Call::INITIATED,
            'ringing' => Call::RINGING,
            'in-progress' => Call::ANSWERED,
            'completed' => Call::COMPLETED,
            'busy', 'no-answer', 'canceled' => Call::NO_ANSWER,
            'failed' => Call::FAILED,
            default => null,
        };
    }

    /**
     * E.164 or nothing.
     *
     * Through the same normaliser the rest of the product uses, so `0551234567`
     * and `+966 55 123 4567` are one number here as they are everywhere else —
     * and so only digits and a plus can reach the dial string or the TwiML,
     * which is the part that would be an injection if it were not.
     */
    private function digits(string $number): string
    {
        return app(PhoneNormalizer::class)->normalize($number) ?? '';
    }
}
