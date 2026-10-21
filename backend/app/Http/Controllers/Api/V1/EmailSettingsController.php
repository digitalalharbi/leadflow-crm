<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Mail\OutboundEmail;
use App\Enums\IntegrationProvider;
use App\Http\Controllers\Controller;
use App\Models\CommissioningRun;
use App\Models\Integration;
use App\Services\AuditLogger;
use App\Services\Email\EmailManager;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Connecting a workspace to its own mail server.
 *
 * The password goes in one direction. It is written encrypted and never read
 * back — the screen shows whether one is stored, never what it is — and saving
 * the form with the field blank leaves it alone rather than erasing it.
 */
class EmailSettingsController extends Controller
{
    public function __construct(private EmailManager $manager, private AuditLogger $audit) {}

    public function show(Request $request)
    {
        $this->authorize('viewAny', Integration::class);

        return ApiResponse::success($this->present($this->manager->integration()));
    }

    /** What the lead screen needs, for anyone who can work a lead. */
    public function capability(Request $request)
    {
        return ApiResponse::success([
            'can_send' => true,
            'mode' => $this->manager->provider(),
            // Whether a customer's reply comes back into the thread, or only
            // to the workspace's own mailbox.
            'replies_tracked' => filled(config('services.email_inbound.domain')) && filled(config('services.email_inbound.secret')),
        ]);
    }

    public function update(Request $request)
    {
        $this->authorize('create', Integration::class);

        $data = $request->validate([
            'host' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:512'],
            'encryption' => ['nullable', Rule::in(['tls', 'ssl'])],
            'from_address' => ['nullable', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:120'],
        ]);

        $integration = $this->manager->integration() ?? Integration::create([
            'provider' => IntegrationProvider::Email,
            'name' => 'Email',
            'status' => Integration::CONFIGURATION_REQUIRED,
            'config' => [],
            'credentials' => [],
            'is_active' => true,
        ]);

        $config = $integration->config ?? [];
        foreach (EmailManager::CONFIG as $key) {
            if (array_key_exists($key, $data)) {
                $config[$key] = $data[$key] ?: null;
            }
        }

        $credentials = $integration->credentials ?? [];
        if (filled($data['password'] ?? null)) {
            $credentials['password'] = $data['password'];
        }

        $integration->forceFill(['config' => $config, 'credentials' => $credentials, 'last_error' => null]);
        // "Configuration required" until a test has gone through: a form that
        // was filled in has not yet been proven to the server it names.
        $integration->status = Integration::CONFIGURATION_REQUIRED;
        $integration->save();

        $this->audit->log('integration.email_updated', $request->user(), $integration, [
            'fields' => array_keys($data),
        ]);

        return ApiResponse::success($this->present($integration->refresh()), 'Saved.');
    }

    /**
     * A real send to the person pressing the button, and nobody else.
     *
     * The only evidence that a mail server accepts these credentials is that it
     * accepted a message, so that is what is asked. The result is recorded, and
     * it is what lifts the integration from "entered" to "ready" — never the
     * form being filled in.
     */
    public function test(Request $request)
    {
        $this->authorize('create', Integration::class);

        $integration = $this->manager->integration();

        if (! $this->manager->isLive($integration)) {
            return ApiResponse::error('Enter the mail server, the sender and the credentials first.', null, 422);
        }

        $from = $this->manager->from($integration, null);
        $text = 'هذه رسالة اختبار من «مهتم». إن وصلتك فإعدادات البريد صحيحة.';

        // The workspace's own server, through the same door every lead email takes: what it says is what the rep will get.
        $result = $this->manager->door($integration)->send(new OutboundEmail(
            $request->user()->email, $request->user()->name, 'اختبار إعدادات البريد', '<p>'.e($text).'</p>', $text,
            $from['address'], $from['name'], '', ['kind' => 'smtp_test'],
        ));
        $ok = $result->ok;
        $reason = $result->error !== null ? mb_substr($result->error, 0, 240) : null;

        $integration->forceFill([
            'status' => $ok ? Integration::CONNECTED : Integration::ERROR,
            'last_error' => $reason,
        ])->save();

        CommissioningRun::create([
            'ran_by' => $request->user()->id,
            'key' => 'email',
            'ok' => $ok,
            'steps' => [['name' => 'smtp_send', 'ok' => $ok, 'detail' => $reason]],
        ]);

        return $ok
            ? ApiResponse::success(['sent_to' => $request->user()->email], 'Test email accepted by the server.')
            : ApiResponse::error('The server refused the test: '.$reason, null, 422);
    }

    public function disconnect(Request $request)
    {
        $this->authorize('create', Integration::class);

        $integration = $this->manager->integration();

        if ($integration) {
            $integration->forceFill([
                'config' => [], 'credentials' => [],
                'status' => Integration::NOT_CONNECTED, 'last_error' => null,
            ])->save();

            $this->audit->log('integration.email_disconnected', $request->user(), $integration, []);
        }

        return ApiResponse::success($this->present($integration?->refresh()));
    }

    private function present(?Integration $integration): array
    {
        return [
            'configured' => $this->manager->isLive($integration),
            'mode' => $this->manager->provider($integration),
            'host' => $integration?->config['host'] ?? null,
            'port' => $integration?->config['port'] ?? null,
            'username' => $integration?->config['username'] ?? null,
            'encryption' => $integration?->config['encryption'] ?? 'tls',
            'from_address' => $integration?->config['from_address'] ?? null,
            'from_name' => $integration?->config['from_name'] ?? null,
            // Present or not — the value itself is never returned.
            'has_password' => filled($integration?->credentials['password'] ?? null),
            'status' => $integration?->status ?? Integration::NOT_CONNECTED,
            'last_error' => $integration?->last_error,
        ];
    }
}
