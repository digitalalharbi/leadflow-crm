<?php

namespace App\Domain\Sheets;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Keeping a Google connection usable.
 *
 * A Google access token lasts an hour. A sync that runs every fifteen minutes
 * will therefore spend most of its life holding an expired one, so refreshing
 * is not an error path here — it is the ordinary case, and the refresh token is
 * the credential that actually matters.
 *
 * Refreshed ahead of expiry rather than on a 401. Waiting for the failure costs
 * a wasted request every hour and, worse, makes "the token expired" and "the
 * token was revoked" look identical at exactly the moment they must not.
 */
class GoogleTokens
{
    /** Refresh this long before expiry, so a slow run never crosses it mid-sync. */
    private const SKEW_SECONDS = 120;

    /**
     * A usable access token, refreshing first if it is about to go stale.
     *
     * Returns null when the connection cannot be made usable — a missing
     * refresh token, a revoked grant, or no OAuth client configured on the
     * platform at all. The caller shows that as a connection problem rather
     * than treating it as an empty sheet.
     */
    public function accessToken(Integration $connection): ?string
    {
        $credentials = $connection->credentials ?? [];
        $expiresAt = $credentials['expires_at'] ?? null;

        $fresh = filled($credentials['access_token'] ?? null)
            && $expiresAt !== null
            && strtotime((string) $expiresAt) - self::SKEW_SECONDS > time();

        if ($fresh) {
            return $credentials['access_token'];
        }

        return $this->refresh($connection);
    }

    public function refresh(Integration $connection): ?string
    {
        $credentials = $connection->credentials ?? [];
        $refreshToken = $credentials['refresh_token'] ?? null;

        $clientId = config('integrations.google_sheets.client_id');
        $clientSecret = config('integrations.google_sheets.client_secret');

        if (blank($refreshToken) || blank($clientId) || blank($clientSecret)) {
            /*
             * Three different missing things with one symptom, so the reason is
             * recorded rather than flattened: the platform has no OAuth client,
             * or this workspace never completed the grant, or the grant was
             * withdrawn. Only the last one is the customer's to fix.
             */
            $this->fail($connection, blank($clientId) || blank($clientSecret)
                ? 'oauth_not_configured'
                : 'no_refresh_token');

            return null;
        }

        try {
            $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);
        } catch (\Throwable $e) {
            // Never the body: a token response carries a token.
            Log::warning('google.refresh_transport_failed', [
                'integration' => $connection->uuid,
                'exception' => $e::class,
            ]);

            $this->fail($connection, 'unreachable');

            return null;
        }

        if ($response->failed()) {
            /*
             * `invalid_grant` is the one that means a person must act: the user
             * revoked access, or changed their password, and no amount of
             * retrying will help. Everything else is worth another sync.
             */
            $reason = ($response->json('error') === 'invalid_grant') ? 'reauth_required' : 'refresh_failed';
            $this->fail($connection, $reason);

            return null;
        }

        $token = $response->json('access_token');
        $expiresIn = (int) ($response->json('expires_in') ?? 3600);

        if (blank($token)) {
            $this->fail($connection, 'refresh_failed');

            return null;
        }

        $connection->forceFill([
            'credentials' => array_merge($credentials, [
                'access_token' => $token,
                'expires_at' => now()->addSeconds($expiresIn)->toIso8601String(),
            ]),
            'status' => Integration::CONNECTED,
            'last_error' => null,
        ])->save();

        return $token;
    }

    private function fail(Integration $connection, string $reason): void
    {
        $connection->forceFill([
            'status' => Integration::ERROR,
            'last_error' => $reason,
        ])->save();
    }
}
