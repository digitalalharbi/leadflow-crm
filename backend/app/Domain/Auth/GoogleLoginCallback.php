<?php

namespace App\Domain\Auth;

use App\Domain\Sheets\GoogleOAuth;

/**
 * Where Google sends someone back after signing in.
 *
 * Signing in on the client that already connects Sheets uses the address that client has registered, so nobody has to
 * register a second one. The Sheets callback recognises the sign-in by its state and passes it on.
 */
class GoogleLoginCallback
{
    public const PATH = '/api/v1/auth/google/callback';

    public static function uri(): string
    {
        $sharesClient = filled(config('integrations.google_sheets.client_id'))
            && config('services.google_login.client_id') === config('integrations.google_sheets.client_id');

        return $sharesClient
            ? app(GoogleOAuth::class)->redirectUri()
            : rtrim((string) config('integrations.app_url'), '/').self::PATH;
    }
}
