<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Sheets\GoogleOAuth;
use App\Domain\Sheets\GoogleTokens;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Connecting a workspace's Google account, and letting go of it again.
 *
 * Two halves in two worlds. Starting the grant is an ordinary authenticated API
 * call from the interface; coming back is a browser redirect from Google that
 * carries no token of ours and lands on the API rather than on the app. So the
 * second half has to establish for itself which workspace it belongs to, and
 * then put the person back where they started.
 */
class GoogleOAuthController extends Controller
{
    /**
     * How long a half-finished consent is allowed to take.
     *
     * Generous enough for somebody to pick between three Google accounts and
     * read a consent screen; short enough that a state parameter copied out of
     * a browser's history is worthless by the time anybody finds it.
     */
    private const STATE_TTL_SECONDS = 900;

    public function __construct(
        private readonly GoogleOAuth $oauth,
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
    ) {}

    /** The URL to send the person to, and the state that proves they came back. */
    public function start(Request $request)
    {
        $this->authorize('create', Integration::class);

        if (! $this->oauth->configured()) {
            // Ours to fix, and it is told apart from the customer's problem
            // everywhere else this connection is described.
            return ApiResponse::error('Google is not configured on this platform.', null, 503);
        }

        $state = Crypt::encryptString(json_encode([
            'tenant' => $request->user()->tenant_id,
            'user' => $request->user()->id,
            'issued' => now()->timestamp,
            // So two consent screens opened from two tabs cannot be confused
            // for each other in a log.
            'nonce' => Str::random(16),
        ]));

        return ApiResponse::success([
            'url' => $this->oauth->authorizeUrl($request->user()->tenant, $state),
        ]);
    }

    /**
     * Google sends the browser here.
     *
     * Unauthenticated by necessity — this is a redirect from another origin —
     * so everything it trusts comes out of the signed state, and every failure
     * ends the same way: back on the integrations screen with a reason, never
     * a stack trace and never a blank page on the API's own domain.
     */
    public function callback(Request $request)
    {
        $payload = $this->readState($request->string('state')->toString());

        if ($payload === null) {
            return $this->back('invalid_state');
        }

        // Google's own refusal — the person pressed cancel, most often.
        if (filled($error = $request->string('error')->toString())) {
            return $this->back($error === 'access_denied' ? 'declined' : 'refused');
        }

        if (blank($code = $request->string('code')->toString())) {
            return $this->back('no_code');
        }

        $tenant = Tenant::find($payload['tenant']);

        if (! $tenant) {
            return $this->back('invalid_state');
        }

        try {
            $tokens = $this->oauth->exchange($code);
        } catch (RuntimeException $e) {
            return $this->back($e->getMessage());
        }

        /*
         * Inside the workspace's own context, explicitly.
         *
         * Nothing about this request carries a tenant — it arrived from Google,
         * not from a signed-in browser — and the model that stores the grant
         * refuses to be created without one. Establishing it from the state is
         * the whole reason the state is signed.
         */
        $this->tenants->withTenant($tenant, function () use ($tokens, $payload) {
            $connection = $this->oauth->store($tokens);

            $this->audit->log('google.connected', User::find($payload['user']), $connection, [
                'scope' => $tokens['scope'],
            ]);
        });

        return $this->back(null);
    }

    /**
     * What the browser needs to open Google's file picker.
     *
     * The picker runs in the page, so it needs an access token there. The one
     * handed over is the workspace's own, short-lived, and — because the grant
     * is `drive.file` — good for the files that person picks and nothing else in
     * their Drive. That is the property that makes sending a token to a browser
     * acceptable at all; it would not be with the old scopes.
     *
     * Admin-only like the rest of connecting a source, and never logged.
     */
    public function picker(Request $request)
    {
        $this->authorize('create', Integration::class);

        if (! $this->oauth->pickerConfigured()) {
            return ApiResponse::error('Google file picker is not configured on this platform.', null, 503);
        }

        $connection = Integration::where('provider', 'google_sheets')->first();

        if (! $connection || blank($connection->credentials['refresh_token'] ?? null)) {
            return ApiResponse::error('Connect a Google account first.', ['connection' => ['sheets.not_connected']], 422);
        }

        $token = app(GoogleTokens::class)->accessToken($connection);

        if (blank($token)) {
            // The connection already holds the reason — revoked, unreachable —
            // and a picker that opens with a dead token fails somewhere the
            // person cannot see why.
            return ApiResponse::error(
                'The Google connection needs to be renewed.',
                ['connection' => ['sheets.reauth_required']],
                422,
            );
        }

        return ApiResponse::success([
            'access_token' => $token,
            'developer_key' => config('integrations.google_sheets.picker_api_key'),
            'app_id' => config('integrations.google_sheets.picker_app_id'),
        ]);
    }

    /** Hands the grant back to Google and forgets our copy of it. */
    public function disconnect(Request $request)
    {
        $this->authorize('create', Integration::class);

        $connection = Integration::where('provider', 'google_sheets')->first();

        if (! $connection) {
            return ApiResponse::success(null, 'Already disconnected.');
        }

        $this->oauth->revoke($connection);
        $this->audit->log('google.disconnected', $request->user(), $connection);

        return ApiResponse::success(null, 'Disconnected.');
    }

    /** @return array{tenant:string,user:string}|null */
    private function readState(string $state): ?array
    {
        if ($state === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($state), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! is_array($payload) || blank($payload['tenant'] ?? null)) {
            return null;
        }

        if ((int) ($payload['issued'] ?? 0) + self::STATE_TTL_SECONDS < now()->timestamp) {
            return null;
        }

        return $payload;
    }

    /**
     * Back to the screen they left, with the answer in the URL.
     *
     * A reason rather than a boolean, because the reasons are different jobs:
     * `oauth_not_configured` is ours, `declined` is a decision somebody made,
     * and `no_refresh_token` means try again with a clean consent.
     */
    private function back(?string $reason)
    {
        $app = rtrim((string) config('integrations.app_url'), '/');

        return redirect()->away($app.'/integrations?google='.($reason === null ? 'connected' : 'error')
            .($reason === null ? '' : '&reason='.urlencode($reason)));
    }
}
