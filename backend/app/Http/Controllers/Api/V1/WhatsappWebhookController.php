<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WhatsappEvent;
use App\Services\Whatsapp\InboundWhatsappProcessor;
use App\Services\Whatsapp\WhatsappManager;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The single public endpoint Meta delivers to.
 *
 * One URL serves every tenant, because Meta allows one callback per app. The
 * tenant is decided by `metadata.phone_number_id` inside the payload — matched
 * against what each workspace saved — and never by anything the caller claims.
 * A delivery whose number we do not recognise is answered 200 and dropped:
 * Meta disables a callback that keeps erroring, and an error here would tell a
 * prober which numbers exist.
 */
class WhatsappWebhookController extends Controller
{
    public function __construct(
        private WhatsappManager $manager,
        private InboundWhatsappProcessor $processor,
        private TenantContext $context,
    ) {}

    /** Meta's subscription handshake: echo hub.challenge when the token matches. */
    public function verify(Request $request)
    {
        $phoneNumberId = (string) $request->query('phone_number_id', '');
        $token = (string) $request->query('hub_verify_token', '');

        // Without a number to scope it to, the only thing we could compare
        // against is every tenant's token in turn — which is an oracle.
        $integration = $phoneNumberId !== ''
            ? $this->manager->integrationForPhoneNumberId($phoneNumberId)
            : null;

        $expected = (string) ($integration?->credentials['verify_token'] ?? '');

        if ($expected !== '' && hash_equals($expected, $token)) {
            return response((string) $request->query('hub_challenge'), 200)
                ->header('Content-Type', 'text/plain');
        }

        return ApiResponse::error('Verification failed.', null, 403);
    }

    public function receive(Request $request)
    {
        $maxBytes = (int) config('integrations.webhooks.max_body_bytes');
        $raw = $request->getContent();

        if (strlen($raw) > $maxBytes) {
            return ApiResponse::error('Payload too large.', null, 413);
        }

        $payload = $request->json()->all();
        $phoneNumberId = $this->phoneNumberIdFrom($payload);

        if ($phoneNumberId === null) {
            return $this->accepted();
        }

        $integration = $this->manager->integrationForPhoneNumberId($phoneNumberId);

        if (! $integration) {
            // Recorded without a tenant: this is the only trace that someone is
            // delivering to numbers nobody here owns.
            WhatsappEvent::create([
                'event_type' => WhatsappEvent::TYPE_REJECTED,
                'event_id' => Str::uuid()->toString(),
                'phone_number_id' => $phoneNumberId,
                'outcome' => 'unknown_phone_number_id',
            ]);

            return $this->accepted();
        }

        $provider = $this->manager->providerFor($integration);

        if (! $provider->verifySignature($integration, $raw, $request->header('X-Hub-Signature-256'))) {
            WhatsappEvent::create([
                'tenant_id' => $integration->tenant_id,
                'integration_id' => $integration->id,
                'event_type' => WhatsappEvent::TYPE_REJECTED,
                'event_id' => Str::uuid()->toString(),
                'phone_number_id' => $phoneNumberId,
                'outcome' => 'invalid_signature',
            ]);

            return ApiResponse::error('Invalid signature.', null, 401);
        }

        try {
            // Everything from here runs inside the tenant, so the global scope
            // is what stops a payload reaching another workspace's rows — not
            // the care of whoever wrote the query.
            $counts = $this->context->withTenant(
                $integration->tenant,
                fn () => $this->processor->process($integration, $payload),
            );
        } catch (\Throwable $e) {
            $reference = (string) Str::uuid();

            // The payload is a customer's message; an exception message can
            // repeat it, so only the class and a reference are logged.
            Log::error('whatsapp.inbound_failed', [
                'integration' => $integration->uuid,
                'reference' => $reference,
                'exception' => $e::class,
                'at' => $e->getFile().':'.$e->getLine(),
            ]);

            // Still a 200: Meta retries on an error, and a payload we cannot
            // parse will fail again on every retry until the callback is
            // disabled. The event log is where this gets investigated.
            return $this->accepted();
        }

        return ApiResponse::success($counts);
    }

    private function phoneNumberIdFrom(array $payload): ?string
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $id = $change['value']['metadata']['phone_number_id'] ?? null;

                if (filled($id)) {
                    return (string) $id;
                }
            }
        }

        return null;
    }

    private function accepted()
    {
        return ApiResponse::success(['messages' => 0, 'statuses' => 0, 'unmatched' => 0, 'duplicates' => 0]);
    }
}
