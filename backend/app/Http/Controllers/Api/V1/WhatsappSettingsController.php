<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\IntegrationProvider;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Services\AuditLogger;
use App\Services\Whatsapp\WhatsappCloudProvider;
use App\Services\Whatsapp\WhatsappManager;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connecting a workspace to its own WhatsApp Business account.
 *
 * Secrets go in one direction only. The access token, verify token and app
 * secret are written encrypted and never read back — the screen shows whether
 * each one is set, never what it is. Rotating is how you replace one.
 */
class WhatsappSettingsController extends Controller
{
    /** Stored encrypted; never returned, never logged. */
    private const SECRETS = ['access_token', 'verify_token', 'app_secret'];

    public function __construct(
        private WhatsappManager $manager,
        private AuditLogger $audit,
        private TenantContext $context,
    ) {}

    public function show(Request $request)
    {
        $this->authorize('viewAny', Integration::class);

        return ApiResponse::success($this->present($this->manager->integration()));
    }

    public function update(Request $request)
    {
        $this->authorize('create', Integration::class);

        $data = $request->validate([
            'phone_number_id' => ['nullable', 'string', 'max:64', 'regex:/^[0-9]+$/'],
            'business_account_id' => ['nullable', 'string', 'max:64', 'regex:/^[0-9]+$/'],
            'access_token' => ['nullable', 'string', 'min:20', 'max:1024'],
            'verify_token' => ['nullable', 'string', 'min:8', 'max:255'],
            'app_secret' => ['nullable', 'string', 'min:16', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $integration = $this->manager->integration() ?? Integration::create([
            'provider' => IntegrationProvider::Whatsapp,
            'name' => 'WhatsApp Business',
            'status' => Integration::CONFIGURATION_REQUIRED,
            'config' => [],
            'credentials' => [],
        ]);

        $this->guardPhoneNumberIsNotClaimedElsewhere($data['phone_number_id'] ?? null, $integration);

        $config = $integration->config ?? [];
        foreach (['phone_number_id', 'business_account_id'] as $key) {
            if (array_key_exists($key, $data)) {
                $config[$key] = $data[$key] ?: null;
            }
        }

        // A blank secret means "leave it alone", not "erase it" — otherwise
        // saving the form to change a phone number would wipe the token.
        $credentials = $integration->credentials ?? [];
        foreach (self::SECRETS as $key) {
            if (filled($data[$key] ?? null)) {
                $credentials[$key] = $data[$key];
            }
        }

        // A workspace that has never set one still needs a verify token before
        // Meta's handshake can succeed, so give it one it can copy.
        $credentials['verify_token'] ??= Str::random(32);

        $integration->forceFill([
            'config' => $config,
            'credentials' => $credentials,
            'is_active' => $data['is_active'] ?? $integration->is_active ?? true,
            'last_error' => null,
        ]);
        $integration->status = $this->manager->isLive($integration)
            ? Integration::CONNECTED
            : Integration::CONFIGURATION_REQUIRED;
        $integration->save();

        // Which keys were set, never their values.
        $this->audit->log('integration.whatsapp_updated', $request->user(), $integration, [
            'fields' => array_keys($data),
        ]);

        return ApiResponse::success($this->present($integration->refresh()), 'Saved.');
    }

    /**
     * A live check that the credentials work, without messaging a customer:
     * read the phone number back from the Graph API.
     */
    public function test(Request $request)
    {
        $this->authorize('create', Integration::class);

        $integration = $this->manager->integration();

        if (! $integration) {
            return ApiResponse::error('WhatsApp is not configured yet.', null, 422);
        }

        if (! $this->manager->isLive($integration)) {
            return ApiResponse::success([
                'ok' => false,
                'mode' => 'mock',
                'detail' => 'Running in demo mode: messages are recorded but not delivered. Add a phone number id and an access token to send for real.',
            ] + $this->present($integration));
        }

        $result = app(WhatsappCloudProvider::class)->describeNumber($integration);

        $integration->forceFill([
            'status' => $result['ok'] ? Integration::CONNECTED : Integration::ERROR,
            'last_error' => $result['ok'] ? null : $result['detail'],
            'last_synced_at' => $result['ok'] ? now() : $integration->last_synced_at,
        ])->save();

        return ApiResponse::success($result + $this->present($integration->refresh()));
    }

    public function rotateVerifyToken(Request $request)
    {
        $this->authorize('create', Integration::class);

        $integration = $this->manager->integration();

        if (! $integration) {
            return ApiResponse::error('WhatsApp is not configured yet.', null, 422);
        }

        $token = Str::random(32);
        $integration->forceFill([
            'credentials' => array_merge($integration->credentials ?? [], ['verify_token' => $token]),
        ])->save();

        $this->audit->log('integration.whatsapp_verify_token_rotated', $request->user(), $integration);

        // Shown once, on the way out of the rotation — the same contract as the
        // inbound webhook secrets.
        return ApiResponse::success([
            'verify_token' => $token,
            'notice' => 'Copy this token into the Meta app now — it cannot be shown again.',
        ]);
    }

    public function disconnect(Request $request)
    {
        $this->authorize('create', Integration::class);

        $integration = $this->manager->integration();

        if (! $integration) {
            return ApiResponse::noContent();
        }

        // Credentials are destroyed, not just deactivated. "Disconnect" has to
        // mean the token is gone, or it is a checkbox pretending to be one.
        $integration->forceFill([
            'credentials' => [],
            'config' => [],
            'is_active' => false,
            'status' => Integration::NOT_CONNECTED,
            'last_error' => null,
        ])->save();

        $this->audit->log('integration.whatsapp_disconnected', $request->user(), $integration);

        return ApiResponse::success($this->present($integration->refresh()), 'Disconnected.');
    }

    /**
     * Meta identifies a delivery by phone number id alone, so two workspaces
     * holding the same one would route each other's conversations.
     */
    private function guardPhoneNumberIsNotClaimedElsewhere(?string $phoneNumberId, Integration $integration): void
    {
        if (! filled($phoneNumberId)) {
            return;
        }

        $taken = $this->context->withoutScope(fn () => Integration::query()
            ->where('provider', IntegrationProvider::Whatsapp)
            ->whereRaw("config->>'phone_number_id' = ?", [$phoneNumberId])
            ->where('id', '!=', $integration->id)
            ->exists());

        if ($taken) {
            throw ValidationException::withMessages([
                'phone_number_id' => ['This WhatsApp number is already connected to another workspace.'],
            ]);
        }
    }

    private function present(?Integration $integration): array
    {
        $config = $integration?->config ?? [];
        $credentials = $integration?->credentials ?? [];

        return [
            'status' => $this->manager->status($integration),
            'mode' => $this->manager->isLive($integration) ? 'live' : 'mock',
            'can_send' => $this->manager->canSend($integration),
            'is_active' => (bool) ($integration?->is_active ?? false),
            'phone_number_id' => $config['phone_number_id'] ?? null,
            'business_account_id' => $config['business_account_id'] ?? null,
            // Presence only. The values never leave the database.
            'has_access_token' => filled($credentials['access_token'] ?? null),
            'has_app_secret' => filled($credentials['app_secret'] ?? null),
            'has_verify_token' => filled($credentials['verify_token'] ?? null),
            'webhook_url' => url('/api/v1/integrations/whatsapp/webhook'),
            'last_error' => $integration?->last_error,
            'last_synced_at' => $integration?->last_synced_at?->toIso8601String(),
        ];
    }
}
