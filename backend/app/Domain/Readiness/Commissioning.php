<?php

namespace App\Domain\Readiness;

use App\Domain\Calls\Providers\TwilioCallProvider;
use App\Domain\Sheets\GoogleOAuth;
use App\Domain\Sheets\GoogleTokens;
use App\Enums\IntegrationProvider;
use App\Models\CommissioningRun;
use App\Models\Integration;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Integrations\LeadPuller;
use App\Services\Integrations\ProviderRegistry;
use App\Services\Whatsapp\WhatsappCloudProvider;
use App\Services\Whatsapp\WhatsappManager;
use Illuminate\Support\Facades\Http;

/**
 * Using a credential once, against the real thing, and saying which part worked.
 *
 * This is the step that turns "somebody typed a token" into either "Meta
 * rejected it, and said why" or "it was accepted, and the first real event is
 * what remains" — without a code change and without a person reading logs. It
 * is the same code path the product uses, run on demand, with its result kept.
 *
 * What it never does is declare anything live. A successful run means the
 * credentials were accepted *today*; live is a thing the other end does, and it
 * is read from evidence by `ProductionReadiness`.
 *
 * Every step returns `ok` true, false, or null for "skipped, and here is why" —
 * a step that did not run must not look like a step that passed.
 */
class Commissioning
{
    public const KEYS = ['google', 'whatsapp', 'twilio', 'ads'];

    public function __construct(
        private readonly GoogleOAuth $google,
        private readonly GoogleTokens $tokens,
        private readonly WhatsappManager $whatsapp,
        private readonly WhatsappCloudProvider $cloud,
        private readonly TwilioCallProvider $twilio,
        private readonly ProviderRegistry $providers,
        private readonly LeadPuller $puller,
    ) {}

    /** @return array{ok:bool, steps:array<int,array{key:string,ok:?bool,detail:string}>} */
    public function run(string $key, Tenant $tenant, ?User $by = null): array
    {
        $steps = match ($key) {
            'google' => $this->google(),
            'whatsapp' => $this->whatsapp(),
            'twilio' => $this->twilio(),
            'ads' => $this->ads($tenant),
        };

        // Skipped steps neither pass nor fail the run.
        $ok = $steps !== [] && collect($steps)->every(fn (array $step) => $step['ok'] !== false)
            && collect($steps)->contains(fn (array $step) => $step['ok'] === true);

        CommissioningRun::create([
            'ran_by' => $by?->id,
            'key' => $key,
            'ok' => $ok,
            'steps' => $steps,
        ]);

        return ['ok' => $ok, 'steps' => $steps];
    }

    // --------------------------------------------------------------- google

    private function google(): array
    {
        $steps = [];

        $steps[] = $this->google->configured()
            ? $this->probeClient()
            : $this->step('oauth_client', false, 'not_configured');

        $steps[] = $this->step(
            'picker',
            $this->google->pickerConfigured(),
            $this->google->pickerConfigured() ? 'configured' : 'picker_not_configured',
        );

        $connection = Integration::where('provider', IntegrationProvider::GoogleSheets->value)->first();

        if (! $connection || blank($connection->credentials['refresh_token'] ?? null)) {
            // Not a failure of the platform: the workspace has not consented yet.
            $steps[] = $this->step('account', null, 'awaiting_consent');

            return $steps;
        }

        $token = $this->tokens->accessToken($connection);

        $steps[] = $this->step('token_refresh', $token !== null, $token !== null ? 'renewed' : ($connection->last_error ?? 'refused'));

        if ($token === null) {
            return $steps;
        }

        $steps[] = $this->scope($token);

        return $steps;
    }

    /**
     * Whether Google accepts the OAuth client, without a user.
     *
     * Redeeming a code that cannot be valid is a real call that no state
     * depends on, and the *kind* of refusal is the answer: `invalid_grant`
     * means Google recognised the client and rejected the code, which is
     * exactly what a correct client looks like; `invalid_client` means the id
     * or the secret is wrong. Nothing else in the console can say that before a
     * person has been through the consent screen.
     */
    private function probeClient(): array
    {
        try {
            $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('integrations.google_sheets.client_id'),
                'client_secret' => config('integrations.google_sheets.client_secret'),
                'code' => 'commissioning-probe',
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->google->redirectUri(),
            ]);
        } catch (\Throwable) {
            return $this->step('oauth_client', false, 'unreachable');
        }

        $error = (string) $response->json('error');

        return match ($error) {
            'invalid_grant' => $this->step('oauth_client', true, 'accepted'),
            // The redirect URI not being on the client's list is reported by
            // Google the same way a wrong code is, so this is the best a probe
            // can do about it; the consent screen is the real test of that one.
            'invalid_client' => $this->step('oauth_client', false, 'client_rejected'),
            default => $this->step('oauth_client', false, $error !== '' ? $error : 'unexpected_response'),
        };
    }

    private function scope(string $token): array
    {
        try {
            $response = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', ['access_token' => $token]);
        } catch (\Throwable) {
            return $this->step('scope', false, 'unreachable');
        }

        if ($response->failed()) {
            return $this->step('scope', false, 'token_rejected');
        }

        $granted = explode(' ', (string) $response->json('scope'));

        return in_array('https://www.googleapis.com/auth/drive.file', $granted, true)
            ? $this->step('scope', true, 'drive_file')
            : $this->step('scope', false, 'missing_drive_file');
    }

    // ------------------------------------------------------------- whatsapp

    private function whatsapp(): array
    {
        $integration = $this->whatsapp->integration();

        if (! $this->whatsapp->isLive($integration)) {
            return [$this->step('credentials', false, 'credentials_missing')];
        }

        $result = $this->cloud->describeNumber($integration);

        return [
            $this->step('credentials', true, 'present'),
            // Meta's own words on failure — they name the permission or id that
            // is wrong — and the verified business name on success, which is
            // what a person checks to see it is their number.
            $this->step('number', (bool) $result['ok'], (string) ($result['verified_name'] ?? $result['detail'])),
        ];
    }

    // --------------------------------------------------------------- twilio

    private function twilio(): array
    {
        if (! $this->twilio->canPlace()) {
            return [$this->step('credentials', false, 'credentials_missing')];
        }

        $result = $this->twilio->verifyAccount();

        return [
            $this->step('credentials', true, 'present'),
            $this->step('account', $result['ok'], $result['ok'] ? (string) ($result['account'] ?? 'active') : $result['detail']),
        ];
    }

    // ------------------------------------------------------------------ ads

    /**
     * The ad platforms, by pulling from each that has credentials.
     *
     * A pull is the real thing and not a rehearsal: it reads the provider's
     * actual leads and ingests them through the same path a scheduled pull
     * does, which is why it is allowed to be the commissioning step. The first
     * run that returns rows is also the first real event, so a workspace that
     * commissions and has leads waiting goes straight to live.
     *
     * Webhook-only platforms cannot be tested from here — they call us — and
     * say so, rather than passing for want of anything to fail.
     */
    private function ads(Tenant $tenant): array
    {
        $steps = [];

        foreach ([
            IntegrationProvider::Meta, IntegrationProvider::TikTok, IntegrationProvider::GoogleAds,
            IntegrationProvider::Snapchat, IntegrationProvider::LinkedIn,
        ] as $provider) {
            $integration = Integration::where('provider', $provider->value)->first();

            if (! $integration || $integration->configuredCredentialKeys() === []) {
                continue;
            }

            $driver = $this->providers->get($provider);

            if (! $driver->pullsLeads()) {
                $steps[] = $this->step($provider->value, null, 'awaiting_first_event');

                continue;
            }

            if (! $driver->isConfigured($integration)) {
                $steps[] = $this->step($provider->value, false, 'incomplete_credentials');

                continue;
            }

            $result = $this->puller->pull($tenant, $integration);

            $steps[] = $this->step(
                $provider->value,
                $result['error'] === null,
                $result['error'] ?? "fetched {$result['fetched']}, new {$result['created']}",
            );
        }

        return $steps === [] ? [$this->step('credentials', false, 'credentials_missing')] : $steps;
    }

    private function step(string $key, ?bool $ok, string $detail): array
    {
        return ['key' => $key, 'ok' => $ok, 'detail' => $detail];
    }
}
