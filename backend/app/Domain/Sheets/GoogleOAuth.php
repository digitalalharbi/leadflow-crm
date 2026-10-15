<?php

namespace App\Domain\Sheets;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Getting the grant in the first place.
 *
 * `GoogleTokens` could refresh an access token and nothing could ever obtain
 * one: there was no authorize step and no callback, so the only way a workspace
 * could reach a connected state was for somebody to paste a refresh token into
 * a credentials field by hand. That is not a flow anybody sells.
 *
 * Two scopes, both read-only, and the narrower of each pair. `spreadsheets.
 * readonly` reads cells; `drive.metadata.readonly` can list a file's name and
 * not a byte of its contents, which is all the picker needs. A product that
 * asks for `drive` to populate a dropdown is one a security review rejects.
 */
class GoogleOAuth
{
    private const AUTHORIZE = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const REVOKE = 'https://oauth2.googleapis.com/revoke';

    public const SCOPES = [
        'https://www.googleapis.com/auth/spreadsheets.readonly',
        'https://www.googleapis.com/auth/drive.metadata.readonly',
    ];

    /** Whether the platform can offer this at all. Ours to fix, not a tenant's. */
    public function configured(): bool
    {
        return filled(config('integrations.google_sheets.client_id'))
            && filled(config('integrations.google_sheets.client_secret'));
    }

    public function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').'/oauth/google/callback';
    }

    /**
     * Where to send the person, and the state that proves they came back.
     *
     * `access_type=offline` with `prompt=consent` is not belt and braces: Google
     * returns a refresh token only on the first consent for a given client and
     * account, so a workspace that reconnects — after an error, or on a second
     * attempt — would otherwise come back with an access token good for an hour
     * and no way to renew it. The symptom is a connection that works until
     * lunchtime.
     */
    public function authorizeUrl(Tenant $tenant, string $state): string
    {
        if (! $this->configured()) {
            throw new RuntimeException('Google is not configured on this platform.');
        }

        return self::AUTHORIZE.'?'.http_build_query([
            'client_id' => config('integrations.google_sheets.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /**
     * Exchanges the one-time code for the credential that lasts.
     *
     * @return array{access_token:string, refresh_token:?string, expires_at:string, scope:string}
     *
     * @throws RuntimeException with a reason a person can act on
     */
    public function exchange(string $code): array
    {
        try {
            $response = Http::asForm()->timeout(15)->post(self::TOKEN, [
                'client_id' => config('integrations.google_sheets.client_id'),
                'client_secret' => config('integrations.google_sheets.client_secret'),
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirectUri(),
            ]);
        } catch (\Throwable $e) {
            // Never the body: a token response carries a token.
            Log::warning('google.exchange_transport_failed', ['exception' => $e::class]);

            throw new RuntimeException('unreachable');
        }

        if ($response->failed()) {
            Log::warning('google.exchange_refused', [
                'status' => $response->status(),
                'error' => $response->json('error'),
            ]);

            throw new RuntimeException((string) ($response->json('error') ?: 'exchange_failed'));
        }

        $body = $response->json();
        $refresh = $body['refresh_token'] ?? null;

        if (blank($body['access_token'] ?? null)) {
            throw new RuntimeException('no_access_token');
        }

        /*
         * A missing refresh token is refused here rather than stored.
         *
         * It happens when Google has consented this account to this client
         * before and `prompt=consent` was lost somewhere — and the connection
         * it produces looks perfect for an hour and then cannot be renewed. A
         * failure now, with a reason, beats a sync that dies at lunchtime.
         */
        if (blank($refresh)) {
            throw new RuntimeException('no_refresh_token');
        }

        return [
            'access_token' => $body['access_token'],
            'refresh_token' => $refresh,
            'expires_at' => now()->addSeconds((int) ($body['expires_in'] ?? 3600))->toIso8601String(),
            'scope' => (string) ($body['scope'] ?? implode(' ', self::SCOPES)),
        ];
    }

    /**
     * Stores the grant against the workspace's one Google connection.
     *
     * `updateOrCreate` on the provider, because a workspace has one Google
     * connection and reconnecting is the ordinary way to fix a revoked grant —
     * creating a second would leave the sheets pointing at the dead one.
     */
    public function store(array $tokens): Integration
    {
        $connection = Integration::firstOrNew(['provider' => IntegrationProvider::GoogleSheets->value]);

        $connection->fill([
            'name' => 'Google Sheets',
            'status' => Integration::CONNECTED,
            'is_active' => true,
            'last_error' => null,
        ]);

        // Merged, not replaced: anything else kept against this connection —
        // a webhook secret, a note — survives a reconnection.
        $connection->credentials = array_merge($connection->credentials ?? [], $tokens);
        $connection->save();

        return $connection;
    }

    /**
     * Hands the grant back to Google, so "disconnect" means disconnected.
     *
     * Best effort by design: if Google cannot be reached we still drop our copy
     * of the credential, because the alternative is a workspace that pressed
     * disconnect and is still connected.
     */
    public function revoke(Integration $connection): void
    {
        $token = $connection->credentials['refresh_token'] ?? null;

        if (filled($token)) {
            try {
                Http::asForm()->timeout(10)->post(self::REVOKE, ['token' => $token]);
            } catch (\Throwable $e) {
                Log::info('google.revoke_failed', ['exception' => $e::class]);
            }
        }

        $connection->forceFill([
            'credentials' => [],
            'status' => Integration::NOT_CONNECTED,
            'last_error' => null,
        ])->save();
    }
}
