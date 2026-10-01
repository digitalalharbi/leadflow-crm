<?php

namespace App\Services\Whatsapp;

use App\DataTransferObjects\SentMessage;
use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp Business Platform (Cloud API).
 *
 * Talks to graph.facebook.com with the tenant's own access token. Nothing here
 * is shared between tenants: the token, the phone number id and the app secret
 * all come off the integration row being used.
 */
class WhatsappCloudProvider implements WhatsappProviderInterface
{
    public function key(): string
    {
        return 'whatsapp_cloud';
    }

    public function sendText(Integration $integration, string $toPhone, string $body): SentMessage
    {
        $credentials = $integration->credentials ?? [];
        $phoneNumberId = $integration->config['phone_number_id'] ?? null;
        $token = $credentials['access_token'] ?? null;

        if (! $phoneNumberId || ! $token) {
            return SentMessage::failed('not_configured', 'WhatsApp is not configured for this workspace.');
        }

        $version = config('services.whatsapp.graph_version');
        $url = sprintf('%s/%s/%s/messages', rtrim(config('services.whatsapp.graph_url'), '/'), $version, $phoneNumberId);

        try {
            $response = Http::withToken($token)
                ->timeout((int) config('services.whatsapp.timeout'))
                ->acceptJson()
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => ltrim($toPhone, '+'),
                    'type' => 'text',
                    'text' => ['preview_url' => false, 'body' => $body],
                ]);
        } catch (\Throwable $e) {
            // The message text is the customer's own words; the exception text
            // may repeat it, so neither goes to the log.
            Log::warning('whatsapp.send_transport_failed', [
                'integration' => $integration->uuid,
                'exception' => $e::class,
            ]);

            return SentMessage::failed('transport_error', 'Could not reach WhatsApp. Try again.');
        }

        $json = $response->json() ?? [];

        if ($response->failed()) {
            $error = $json['error'] ?? [];

            return SentMessage::failed(
                (string) ($error['code'] ?? $response->status()),
                (string) ($error['message'] ?? 'WhatsApp rejected this message.'),
                $this->redact($json),
            );
        }

        $id = $json['messages'][0]['id'] ?? null;

        return $id
            ? SentMessage::accepted($id, $this->redact($json))
            : SentMessage::failed('no_message_id', 'WhatsApp accepted the request without returning a message id.');
    }

    /**
     * Meta signs each delivery with the app secret, as `sha256=<hmac>`.
     *
     * Compared with hash_equals: a plain === leaks where the first byte differs,
     * which is enough to forge a signature given enough attempts.
     */
    public function verifySignature(Integration $integration, string $rawBody, ?string $signature): bool
    {
        $secret = $integration->credentials['app_secret'] ?? null;

        if (! $secret || ! $signature) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Read the connected number back from the Graph API.
     *
     * A connection test has to prove the credentials work without messaging a
     * real customer, so it asks the API to describe the number we would send
     * from — the cheapest call that exercises the token, the id and the
     * permission together.
     *
     * @return array{ok: bool, detail: string, display_number?: ?string, verified_name?: ?string}
     */
    public function describeNumber(Integration $integration): array
    {
        $phoneNumberId = $integration->config['phone_number_id'] ?? null;
        $token = $integration->credentials['access_token'] ?? null;

        if (! $phoneNumberId || ! $token) {
            return ['ok' => false, 'detail' => 'Add a phone number id and an access token first.'];
        }

        $url = sprintf(
            '%s/%s/%s',
            rtrim(config('services.whatsapp.graph_url'), '/'),
            config('services.whatsapp.graph_version'),
            $phoneNumberId,
        );

        try {
            $response = Http::withToken($token)
                ->timeout((int) config('services.whatsapp.timeout'))
                ->acceptJson()
                ->get($url, ['fields' => 'display_phone_number,verified_name,quality_rating']);
        } catch (\Throwable $e) {
            Log::warning('whatsapp.test_transport_failed', [
                'integration' => $integration->uuid,
                'exception' => $e::class,
            ]);

            return ['ok' => false, 'detail' => 'Could not reach WhatsApp.'];
        }

        if ($response->failed()) {
            $error = $response->json('error') ?? [];

            return [
                'ok' => false,
                // Meta's own message is the useful part here and contains no
                // customer data — it names the permission or id that is wrong.
                'detail' => (string) ($error['message'] ?? 'WhatsApp rejected these credentials.'),
            ];
        }

        return [
            'ok' => true,
            'detail' => 'Connected.',
            'display_number' => $response->json('display_phone_number'),
            'verified_name' => $response->json('verified_name'),
        ];
    }

    /** Never keep a token that came back inside an error envelope. */
    private function redact(array $payload): array
    {
        array_walk_recursive($payload, function (&$value, $key) {
            if (is_string($key) && preg_match('/token|secret|signature/i', $key)) {
                $value = '[redacted]';
            }
        });

        return $payload;
    }
}
