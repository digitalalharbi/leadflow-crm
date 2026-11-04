<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google_login.client_id' => 'client', 'services.google_login.client_secret' => 'secret', 'integrations.app_url' => 'https://mohtam.pro']);
        $this->tenant = $this->provisionTenant()['tenant'];
        Cache::flush();
        Http::fake(['accounts.google.com/*' => Http::response('', 302, ['Location' => 'https://accounts.google.com/v3/signin/identifier'])]);
    }

    /** Sends the person to Google, then brings them back with this answer. Returns the response of the callback. */
    private function signIn(array $claims = [], ?string $cookie = null, ?string $forceState = null)
    {
        $start = $this->get('/api/v1/auth/google/redirect')->assertRedirect();
        parse_str((string) parse_url($start->headers->get('Location'), PHP_URL_QUERY), $query);
        $binding = $start->headers->getCookies()[0]->getValue();

        Http::swap(new Factory);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['id_token' => 'x.'.rtrim(strtr(base64_encode(json_encode($claims + [
            'iss' => 'https://accounts.google.com', 'aud' => 'client', 'exp' => now()->addHour()->timestamp,
            'sub' => 'google-1', 'email' => 'p@x.test', 'email_verified' => true, 'nonce' => $query['nonce'],
        ])), '+/', '-_'), '=').'.sig'])]);

        return $this->withUnencryptedCookie('mohtam_google', $cookie ?? $binding)
            ->get('/api/v1/auth/google/callback?code=abc&state='.($forceState ?? $query['state']));
    }

    private function ticketFrom($response): string
    {
        $response->assertRedirect();
        $this->assertSame(1, preg_match('/#google=(\w{48})$/', (string) $response->headers->get('Location'), $m), (string) $response->headers->get('Location'));

        return $m[1];
    }

    public function test_it_is_offered_only_when_configured(): void
    {
        $this->getJson('/api/v1/auth/providers')->assertJsonPath('data.providers.0.key', 'google');

        config(['services.google_login.client_id' => null]);
        $this->getJson('/api/v1/auth/providers')->assertJsonPath('data.providers', []);
        $this->get('/api/v1/auth/google/redirect')->assertNotFound();
    }

    public function test_a_proven_account_links_and_signs_in_and_the_ticket_is_good_once(): void
    {
        $user = $this->makeUser($this->tenant, Role::Sales, ['email' => 'p@x.test']);
        $user->forceFill(['email_verified_at' => now()])->save();

        $ticket = $this->ticketFrom($this->signIn());
        $this->assertSame('google-1', $user->fresh()->google_id);

        $this->postJson('/api/v1/auth/google/complete', ['ticket' => $ticket])->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);
        $this->postJson('/api/v1/auth/google/complete', ['ticket' => $ticket])->assertUnprocessable();
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'auth.google_login')->exists());
    }

    public function test_it_never_creates_an_account(): void
    {
        $users = User::withoutGlobalScopes()->count();

        $this->signIn(['email' => 'stranger@x.test'])->assertRedirect('https://mohtam.pro/login?google_error=no_account');

        $this->assertSame($users, User::withoutGlobalScopes()->count());
    }

    public function test_an_address_typed_onto_someones_row_gets_nothing(): void
    {
        $impostor = $this->makeUser($this->tenant, Role::Admin, ['email' => 'p@x.test']);

        $this->signIn()->assertRedirect('https://mohtam.pro/login?google_error=no_account');

        $this->assertNull($impostor->fresh()->google_id);
    }

    public function test_an_unconfirmed_google_address_and_the_platform_owner_are_refused(): void
    {
        $user = $this->makeUser($this->tenant, Role::Sales, ['email' => 'p@x.test']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->signIn(['email_verified' => false])->assertRedirect('https://mohtam.pro/login?google_error=unverified');

        $this->makePlatformOwner('owner@x.test')->forceFill(['email_verified_at' => now()])->save();
        $this->signIn(['email' => 'owner@x.test'])->assertRedirect('https://mohtam.pro/login?google_error=no_account');
    }

    public function test_another_google_identity_cannot_take_a_linked_account(): void
    {
        $user = $this->makeUser($this->tenant, Role::Sales, ['email' => 'p@x.test']);
        $user->forceFill(['email_verified_at' => now(), 'google_id' => 'google-1'])->save();

        $this->signIn(['sub' => 'google-2'])->assertRedirect('https://mohtam.pro/login?google_error=linked_elsewhere');
        $this->assertSame('google-1', $user->fresh()->google_id);
    }

    public function test_a_forged_state_another_browser_a_wrong_audience_or_nonce_are_refused(): void
    {
        $user = $this->makeUser($this->tenant, Role::Sales, ['email' => 'p@x.test']);
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->signIn([], null, 'forged')->assertRedirect('https://mohtam.pro/login?google_error=failed');
        $this->signIn([], 'another-browser')->assertRedirect('https://mohtam.pro/login?google_error=failed');
        $this->signIn(['aud' => 'someone-else'])->assertRedirect('https://mohtam.pro/login?google_error=failed');
        $this->signIn(['nonce' => 'replayed'])->assertRedirect('https://mohtam.pro/login?google_error=failed');
        $this->assertNull($user->fresh()->google_id);
    }

    public function test_google_does_not_get_past_a_second_factor(): void
    {
        $user = $this->makeUser($this->tenant, Role::Sales, ['email' => 'p@x.test']);
        $secret = Totp::generateSecret();
        $user->forceFill(['email_verified_at' => now(), 'mfa_secret' => $secret, 'mfa_enabled_at' => now()])->save();

        $ticket = $this->ticketFrom($this->signIn());

        $this->postJson('/api/v1/auth/google/complete', ['ticket' => $ticket])->assertOk()->assertJsonPath('data.mfa_required', true)->assertJsonMissingPath('data.token');
        $this->postJson('/api/v1/auth/google/complete', ['ticket' => $ticket, 'code' => '000000'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/google/complete', ['ticket' => $ticket, 'code' => Totp::code($secret)])->assertOk()->assertJsonStructure(['data' => ['token']]);
    }

    public function test_removing_the_link_takes_the_password_and_changing_the_email_ends_it(): void
    {
        $user = $this->makeUser($this->tenant, Role::Sales, ['email' => 'p@x.test', 'password' => 'a-strong-pass']);
        $user->forceFill(['email_verified_at' => now(), 'google_id' => 'google-1'])->save();

        $this->actingAs($user)->deleteJson('/api/v1/auth/security/google', ['password' => 'wrong'])->assertUnprocessable();
        $this->assertNotNull($user->fresh()->google_id);
        $this->actingAs($user)->deleteJson('/api/v1/auth/security/google', ['password' => 'a-strong-pass'])->assertOk();
        $this->assertNull($user->fresh()->google_id);

        $user->forceFill(['google_id' => 'google-1'])->save();
        $this->actingAs($user->fresh())->patchJson("/api/v1/users/{$user->uuid}", ['email' => 'q@x.test', 'current_password' => 'a-strong-pass'])->assertOk();
        $this->assertNull($user->fresh()->google_id);
    }

    public function test_a_button_google_would_refuse_is_not_offered_and_readiness_says_why(): void
    {
        Cache::flush();
        Http::swap(new Factory);
        Http::fake(['accounts.google.com/*' => Http::response('', 302, ['Location' => 'https://accounts.google.com/signin/oauth/error/v2?authError=x'])]);

        $this->getJson('/api/v1/auth/providers')->assertOk()->assertJsonPath('data.providers', []);
    }

    public function test_when_google_cannot_be_reached_the_button_stays(): void
    {
        Cache::flush();
        Http::swap(new Factory);
        Http::fake(['accounts.google.com/*' => Http::response('', 503)]);

        $this->getJson('/api/v1/auth/providers')->assertOk()->assertJsonPath('data.providers.0.key', 'google');
    }

    public function test_a_button_google_accepts_is_offered(): void
    {
        Cache::flush();
        Http::swap(new Factory);
        Http::fake(['accounts.google.com/*' => Http::response('', 302, ['Location' => 'https://accounts.google.com/v3/signin/identifier?x=1'])]);

        $this->getJson('/api/v1/auth/providers')->assertOk()->assertJsonPath('data.providers.0.key', 'google');
    }
}
