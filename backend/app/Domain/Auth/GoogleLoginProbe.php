<?php

namespace App\Domain\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Whether Google will accept this site's callback for the client it holds, asked without anybody signing in.
 *
 * A sign-in button Google answers with an error page is worse than no button. A refusal hides the button and
 * readiness says why; if Google could not be reached nothing is known, and the button stays.
 */
class GoogleLoginProbe
{
    private const AUTHORIZE = 'https://accounts.google.com/o/oauth2/v2/auth';

    /** @return bool|null null when Google gave no answer */
    public function accepted(): ?bool
    {
        $clientId = (string) config('services.google_login.client_id');
        $redirect = rtrim((string) config('integrations.app_url'), '/').'/api/v1/auth/google/callback';

        $answer = Cache::remember('google-login-probe:'.hash('sha256', $clientId.$redirect), now()->addMinutes(15), fn () => $this->ask($clientId, $redirect));

        return $answer === 'unknown' ? null : $answer === 'accepted';
    }

    private function ask(string $clientId, string $redirect): string
    {
        try {
            $response = Http::timeout(5)->withoutRedirecting()->get(self::AUTHORIZE, [
                'client_id' => $clientId,
                'redirect_uri' => $redirect,
                'response_type' => 'code',
                'scope' => 'openid email',
            ]);
        } catch (Throwable) {
            return 'unknown';
        }

        if ($response->serverError()) {
            return 'unknown';
        }

        return $response->clientError() || str_contains((string) $response->header('Location'), '/oauth/error') ? 'refused' : 'accepted';
    }
}
