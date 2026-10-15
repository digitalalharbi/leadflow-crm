<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Calls\Providers\TwilioCallProvider;
use App\Enums\IntegrationProvider;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Connecting a workspace's own telephony account.
 *
 * Mirrors the WhatsApp card on purpose: credentials are the workspace's, they
 * are write-only once saved, and the screen can prove they work without placing
 * a call to a real customer.
 */
class CallSettingsController extends Controller
{
    public function __construct(
        private readonly TwilioCallProvider $twilio,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request)
    {
        $this->authorize('viewAny', Integration::class);

        return ApiResponse::success(['calls' => $this->present($this->twilio->integration())]);
    }

    public function update(Request $request)
    {
        $this->authorize('create', Integration::class);

        $data = $request->validate([
            'account_sid' => ['sometimes', 'string', 'regex:/^AC[0-9a-f]{32}$/i'],
            // Sent only when it changes. Absent means "keep what is stored", which
            // is what lets the form show the field empty without a save wiping it.
            'auth_token' => ['sometimes', 'nullable', 'string', 'min:16', 'max:64'],
            'from_number' => ['sometimes', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
        ]);

        $integration = Integration::firstOrNew(['provider' => IntegrationProvider::TwilioVoice->value]);

        $credentials = $integration->credentials ?? [];

        foreach (['account_sid', 'auth_token'] as $key) {
            if (filled($data[$key] ?? null)) {
                $credentials[$key] = $data[$key];
            }
        }

        $integration->fill([
            'name' => 'Twilio Voice',
            'is_active' => true,
            'status' => Integration::CONFIGURATION_REQUIRED,
            'last_error' => null,
        ]);

        $integration->config = array_merge($integration->config ?? [], array_filter([
            'from_number' => $data['from_number'] ?? null,
        ]));
        $integration->credentials = $credentials;
        $integration->save();

        // Connected is a claim about credentials that work, not credentials that
        // were typed: it is set by `test`, never here.
        $this->audit->log('calls.updated', $request->user(), $integration, [
            'from_number' => $integration->config['from_number'] ?? null,
        ]);

        return ApiResponse::success(['calls' => $this->present($integration)]);
    }

    /**
     * Asks Twilio to describe the account.
     *
     * The cheapest call that exercises the SID and the token together without
     * ringing anybody, and the only thing that earns the word "connected".
     */
    public function test(Request $request)
    {
        $this->authorize('create', Integration::class);

        $integration = $this->twilio->integration();

        if (! $integration || ! $this->twilio->canPlace()) {
            return ApiResponse::error('Telephony is not configured.', null, 422);
        }

        $sid = (string) $integration->credentials['account_sid'];

        try {
            $response = Http::withBasicAuth($sid, (string) $integration->credentials['auth_token'])
                ->timeout(15)
                ->acceptJson()
                ->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}.json");
        } catch (\Throwable) {
            return ApiResponse::error('Twilio could not be reached.', null, 502);
        }

        if ($response->failed() || $response->json('status') !== 'active') {
            $reason = (string) ($response->json('message') ?: 'Twilio did not accept these credentials.');

            $integration->forceFill(['status' => Integration::ERROR, 'last_error' => $reason])->save();

            return ApiResponse::error($reason, null, 422);
        }

        $integration->forceFill([
            'status' => Integration::CONNECTED,
            'last_error' => null,
            'last_synced_at' => now(),
        ])->save();

        return ApiResponse::success([
            'calls' => $this->present($integration),
            'account' => $response->json('friendly_name'),
        ]);
    }

    public function destroy(Request $request)
    {
        $this->authorize('create', Integration::class);

        $integration = $this->twilio->integration();

        if ($integration) {
            $integration->forceFill([
                'credentials' => [],
                'status' => Integration::NOT_CONNECTED,
                'last_error' => null,
            ])->save();

            $this->audit->log('calls.disconnected', $request->user(), $integration);
        }

        return ApiResponse::success(null, 'Disconnected.');
    }

    /** Never the token, and never the SID in full. */
    private function present(?Integration $integration): array
    {
        $sid = (string) ($integration?->credentials['account_sid'] ?? '');

        return [
            'provider' => 'twilio',
            'configured' => $this->twilio->canPlace(),
            'status' => $integration?->status ?? Integration::NOT_CONNECTED,
            'from_number' => $integration?->config['from_number'] ?? null,
            'account_sid_hint' => $sid === '' ? null : substr($sid, 0, 4).'…'.substr($sid, -4),
            'has_token' => filled($integration?->credentials['auth_token'] ?? null),
            'last_error' => $integration?->last_error,
            // What to paste into Twilio, so nobody has to construct it by hand.
            'status_callback' => $integration ? $this->twilio->statusUrl($integration) : null,
        ];
    }
}
