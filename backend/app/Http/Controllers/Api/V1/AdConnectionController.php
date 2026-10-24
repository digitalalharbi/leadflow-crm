<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Campaigns\AdPlatform;
use App\Domain\Campaigns\AdPlatformRegistry;
use App\Domain\Campaigns\PlatformVerification;
use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use App\Models\AdConnection;
use App\Models\Integration;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Connecting an ad account for campaign management. Admins only: these are the
 * keys to somebody's advertising budget.
 */
class AdConnectionController extends Controller
{
    public function __construct(private AdPlatformRegistry $registry, private PlatformVerification $verification, private AuditLogger $audit) {}

    public function show(string $platform)
    {
        $this->authorize('viewAny', Integration::class);
        abort_unless(in_array($platform, AdPlatform::values(), true), 404);

        return ApiResponse::success(['connection' => $this->present($platform)]);
    }

    public function update(Request $request, string $platform)
    {
        $this->authorize('create', Integration::class);
        abort_unless(in_array($platform, AdPlatform::values(), true), 404);
        $adapter = $this->registry->get($platform);

        $keys = array_merge($adapter->requiredCredentials(), array_values(array_intersect($adapter->optionalSettings(), ['app_id', 'secret', 'refresh_token', 'client_id', 'client_secret', 'login_customer_id'])));

        $data = $request->validate([
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:4000'],
            'config' => ['nullable', 'array'],
            'config.account_ids' => ['nullable', 'array', 'max:50'],
            'config.account_ids.*' => ['string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'config.page_ids' => ['nullable', 'array', 'max:50'],
            'config.page_ids.*' => ['string', 'max:64', 'regex:/^\d+$/'],
            'config.api_version' => ['nullable', 'string', 'max:16', 'regex:/^v?\d+(\.\d+)?$/'],
            'config.funding_instrument_id' => ['nullable', 'string', 'max:64'],
            'config.login_customer_id' => ['nullable', 'string', 'max:32'],
            'mode' => ['nullable', Rule::in(['live', 'sandbox'])],
        ]);

        $connection = AdConnection::query()->firstOrNew(['platform' => $platform]);

        // Only the keys this platform uses are stored; a blank one keeps what is there.
        $credentials = $connection->credentials ?? [];
        foreach ($data['credentials'] ?? [] as $key => $value) {
            if (in_array($key, $keys, true) && filled($value)) {
                $credentials[$key] = $value;
            }
        }

        $connection->fill([
            'credentials' => $credentials,
            'config' => array_filter($data['config'] ?? $connection->config ?? [], fn ($v) => $v !== null && $v !== []),
            'mode' => $data['mode'] ?? $connection->mode ?? 'live',
            'is_active' => true,
        ]);
        // New credentials prove nothing yet.
        $connection->forceFill(['last_test_ok_at' => null, 'last_sync_ok_at' => null, 'last_error' => null])->save();

        // Which keys changed, never their values.
        $this->audit->log('ad_connection.saved', $request->user(), $connection, ['platform' => $platform, 'keys' => array_keys(array_filter($data['credentials'] ?? [], 'filled'))]);

        return ApiResponse::success(['connection' => $this->present($platform)]);
    }

    /** Ask the platform who we are: the cheapest call that proves the credentials. */
    public function test(string $platform)
    {
        $this->authorize('create', Integration::class);
        $connection = AdConnection::query()->where('platform', $platform)->firstOrFail();
        $adapter = $this->registry->get($platform);

        if (! $adapter->isConfigured($connection)) {
            return ApiResponse::error('missing_credentials', null, 422);
        }

        try {
            $accounts = $adapter->accounts($connection);
            $connection->forceFill(['last_test_ok_at' => now(), 'last_error' => null])->save();

            return ApiResponse::success(['ok' => true, 'accounts' => count($accounts), 'connection' => $this->present($platform)]);
        } catch (Throwable $e) {
            $connection->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500)])->save();

            return ApiResponse::success(['ok' => false, 'error' => $e->getMessage(), 'connection' => $this->present($platform)]);
        }
    }

    public function destroy(Request $request, string $platform)
    {
        $this->authorize('create', Integration::class);
        AdConnection::query()->where('platform', $platform)->update(['is_active' => false, 'credentials' => null]);
        $this->audit->log('ad_connection.removed', $request->user(), null, ['platform' => $platform]);

        return ApiResponse::success(['connection' => $this->present($platform)]);
    }

    public function leadForms(string $platform)
    {
        $this->authorize('viewAny', Integration::class);
        $connection = AdConnection::for($platform);
        $adapter = $this->registry->get($platform);

        if (! $adapter->isConfigured($connection)) {
            return ApiResponse::success(['supported' => $adapter->isImplemented(), 'forms' => []]);
        }

        $forms = [];
        $supported = true;

        foreach (AdAccount::query()->where('platform', $platform)->get() as $account) {
            try {
                $list = $adapter->leadForms($connection, $account);
            } catch (Throwable $e) {
                return ApiResponse::success(['supported' => true, 'forms' => $forms, 'error' => $e->getMessage()]);
            }

            if ($list === null) {
                $supported = false;
                break;
            }

            foreach ($list as $form) {
                $forms[] = $form + ['account' => $account->name ?? $account->external_id];
            }
        }

        return ApiResponse::success(['supported' => $supported, 'forms' => $forms]);
    }

    private function present(string $platform): array
    {
        $adapter = $this->registry->get($platform);
        $connection = AdConnection::for($platform);
        $stored = $connection?->exists ? $connection : null;

        return [
            'platform' => $platform,
            'built' => $adapter->isImplemented(),
            'state' => $this->verification->state($adapter, $connection),
            'mode' => $connection?->mode ?? 'live',
            'required' => $adapter->requiredCredentials(),
            'optional' => $adapter->optionalSettings(),
            // Which keys are filled, never what they are.
            'filled' => array_keys(array_filter($connection?->credentials ?? [], 'filled')),
            'config' => $stored?->config ?? [],
            'capabilities' => $adapter->capabilities(),
            'last_test_ok_at' => $connection?->last_test_ok_at?->toIso8601String(),
            'last_sync_ok_at' => $connection?->last_sync_ok_at?->toIso8601String(),
            'last_error' => $connection?->last_error,
            'stand_in' => $connection !== null && ! $connection->exists,
        ];
    }
}
