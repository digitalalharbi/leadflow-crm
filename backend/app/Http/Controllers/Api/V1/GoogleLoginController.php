<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\GoogleLoginProbe;
use App\Domain\Mail\AccountEmail;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Signing in with Google, for people who already have an account: it never creates one. The code flow with state, a
 * browser-bound cookie, PKCE and a nonce. An account links only when its own address is already proven, or it is already
 * linked to this Google identity, so an address typed onto somebody's row gets nothing. It stands in for the password
 * and nothing more: an account with a second factor is still asked for it. The platform's own accounts cannot use it.
 */
class GoogleLoginController extends Controller
{
    private const COOKIE = 'mohtam_google';

    private const AUTHORIZE = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN = 'https://oauth2.googleapis.com/token';

    public function __construct(private AuditLogger $audit, private AccountEmail $email) {}

    /** The other ways in that really work right now. */
    public function providers()
    {
        return ApiResponse::success(['providers' => $this->configured() && app(GoogleLoginProbe::class)->accepted() !== false
            ? [['key' => 'google', 'label' => 'Google', 'url' => '/api/v1/auth/google/redirect']]
            : []]);
    }

    public function redirect()
    {
        abort_unless($this->configured(), 404);

        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);
        $binding = Str::random(40);

        Cache::put($this->stateKey($state), ['nonce' => $nonce, 'verifier' => $verifier, 'binding' => hash('sha256', $binding)], 600);

        $query = http_build_query([
            'client_id' => config('services.google_login.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ]);

        return redirect()->away(self::AUTHORIZE.'?'.$query)
            ->cookie(self::COOKIE, $binding, 10, '/api/v1/auth/google', null, str_starts_with($this->appUrl(), 'https://'), true, false, 'lax');
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless($this->configured(), 404);

        $saved = Cache::pull($this->stateKey((string) $request->query('state')));

        // The state was ours, and this is the browser that started it: nobody can sign someone in as someone else.
        if (! is_array($saved) || ! hash_equals($saved['binding'], hash('sha256', (string) $request->cookie(self::COOKIE))) || blank($request->query('code'))) {
            return $this->back('failed');
        }

        $claims = $this->exchange((string) $request->query('code'), $saved);

        if ($claims === null || ($claims['nonce'] ?? null) !== $saved['nonce']) {
            return $this->back('failed');
        }

        if (($claims['email_verified'] ?? false) !== true && ($claims['email_verified'] ?? null) !== 'true') {
            return $this->back('unverified');
        }

        $email = Str::lower((string) ($claims['email'] ?? ''));
        $subject = (string) ($claims['sub'] ?? '');
        $accounts = $this->accountsFor($subject, $email);

        if ($accounts->isEmpty()) {
            $clash = User::query()->whereNotNull('tenant_id')->where('email', $email)->whereNotNull('google_id')->where('google_id', '!=', $subject)->exists();
            Log::warning('auth.google_refused', ['email_hash' => hash('sha256', $email), 'ip' => $request->ip()]);

            return $this->back($clash ? 'linked_elsewhere' : 'no_account');
        }

        $accounts->whereNull('google_id')->each(function (User $account) use ($subject, $request) {
            $account->forceFill(['google_id' => $subject])->save();
            $this->audit->logForTenant((int) $account->tenant_id, 'auth.google_linked', $account, $account, ['ip' => $request->ip()]);
            $this->email->securityNotice($account, 'google_linked');
        });

        $ticket = Str::random(48);
        Cache::put($this->ticketKey($ticket), $accounts->first()->id, 300);

        // In the fragment: it reaches the page and no server log, and the page removes it at once.
        return redirect()->away($this->appUrl().'/login#google='.$ticket);
    }

    /** The ticket and, when the account has one, its second factor. Good once. */
    public function complete(Request $request)
    {
        $data = $request->validate([
            'ticket' => ['required', 'string', 'size:48'],
            'code' => ['nullable', 'string', 'max:32'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $id = Cache::get($this->ticketKey($data['ticket']));
        $user = $id ? User::query()->with('tenant')->whereNotNull('tenant_id')->where('is_active', true)->find($id) : null;

        if ($user === null || ! $user->tenant?->is_active) {
            throw ValidationException::withMessages(['ticket' => ['This sign-in has expired. Try again.']]);
        }

        $auth = app(AuthController::class);

        if ($refused = $auth->secondFactorStep($user, $data['code'] ?? null, $request)) {
            return $refused;
        }

        if (Cache::pull($this->ticketKey($data['ticket'])) === null) {
            throw ValidationException::withMessages(['ticket' => ['This sign-in has expired. Try again.']]);
        }

        return ApiResponse::success($auth->issueSession($user, $request, 'auth.google_login', $request->boolean('remember', true) ? null : now()->addHours(12)));
    }

    /** Takes the password: a stolen session cannot quietly change how the account gets in. */
    public function unlink(Request $request)
    {
        $user = $request->user();
        $data = $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => ['The current password is incorrect.']]);
        }

        $user->forceFill(['google_id' => null])->save();
        $this->audit->log('auth.google_unlinked', $user, $user, ['ip' => $request->ip()]);
        $this->email->securityNotice($user, 'google_unlinked');

        return ApiResponse::success(null, 'Google sign-in removed.');
    }

    /** Most recently used first. */
    private function accountsFor(string $subject, string $email)
    {
        if ($subject === '' || $email === '') {
            return collect();
        }

        return User::query()->whereNotNull('tenant_id')->where('is_active', true)
            ->whereHas('tenant', fn ($q) => $q->where('is_active', true))
            ->where(fn ($q) => $q->where('google_id', $subject)
                ->orWhere(fn ($new) => $new->where('email', $email)->whereNotNull('email_verified_at')->whereNull('google_id')))
            ->orderByRaw('last_login_at desc nulls last')->get();
    }

    /** The code for Google's answer, directly over TLS. @return array<string, mixed>|null */
    private function exchange(string $code, array $saved): ?array
    {
        try {
            $response = Http::asForm()->timeout(15)->post(self::TOKEN, [
                'client_id' => config('services.google_login.client_id'),
                'client_secret' => config('services.google_login.client_secret'),
                'redirect_uri' => $this->redirectUri(),
                'grant_type' => 'authorization_code',
                'code' => $code,
                'code_verifier' => $saved['verifier'],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        $parts = explode('.', (string) $response->json('id_token'));

        if (! $response->successful() || count($parts) !== 3) {
            return null;
        }

        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);

        $good = is_array($claims)
            && in_array($claims['iss'] ?? null, ['https://accounts.google.com', 'accounts.google.com'], true)
            && ($claims['aud'] ?? null) === config('services.google_login.client_id')
            && (int) ($claims['exp'] ?? 0) > now()->timestamp;

        return $good ? $claims : null;
    }

    private function back(string $reason): RedirectResponse
    {
        return redirect()->away($this->appUrl().'/login?google_error='.$reason);
    }

    private function configured(): bool
    {
        return filled(config('services.google_login.client_id')) && filled(config('services.google_login.client_secret'));
    }

    private function appUrl(): string
    {
        return rtrim((string) config('integrations.app_url'), '/');
    }

    private function redirectUri(): string
    {
        return $this->appUrl().'/api/v1/auth/google/callback';
    }

    private function stateKey(string $state): string
    {
        return 'google-login-state:'.hash('sha256', $state);
    }

    private function ticketKey(string $ticket): string
    {
        return 'google-login-ticket:'.hash('sha256', $ticket);
    }
}
