<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A Google Ads access token, minted from a refresh token and cached a little
 * under its hour. Shared by campaign management and conversion uploads, so the
 * two never disagree about how to authenticate. The tokens are never logged.
 */
final class GoogleAdsToken
{
    public static function mint(string $clientId, string $clientSecret, string $refreshToken): string
    {
        return Cache::remember('google-ads-token:'.sha1($clientId.'|'.$refreshToken), 3000, function () use ($clientId, $clientSecret, $refreshToken) {
            $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);

            if ($response->failed() || ! $response->json('access_token')) {
                throw new RuntimeException('Google refused the refresh token: '.$response->json('error_description', $response->json('error', 'unknown')));
            }

            return (string) $response->json('access_token');
        });
    }
}
