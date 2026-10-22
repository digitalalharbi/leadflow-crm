<?php

namespace Tests\Feature;

use App\Domain\Sheets\GoogleOAuth;
use App\Enums\Role;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A workspace can connect its own Google account.
 *
 * `GoogleTokens` could refresh an access token from the day it was written, and
 * nothing could ever obtain one: there was no authorize step and no callback,
 * so the only route to a connected state was somebody pasting a refresh token
 * into a credentials field. Every test passed the whole time, because every
 * test started from a connection that already existed.
 *
 * The callback is the interesting half. It arrives from Google rather than from
 * the interface, so it carries no session, no tenant and nothing of ours except
 * the state we signed — and it must be impossible to use that fact to write
 * into somebody else's workspace.
 */
class ConnectingAGoogleAccountTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Sheets Co');

        config([
            'integrations.google_sheets.client_id' => 'client-123.apps.googleusercontent.com',
            'integrations.google_sheets.client_secret' => 'secret-123',
            'integrations.app_url' => 'https://app.example.test',
        ]);
    }

    private function state(array $overrides = []): string
    {
        return Crypt::encryptString(json_encode(array_merge([
            'tenant' => $this->t['tenant']->id,
            'user' => $this->t['admin']->id,
            'issued' => now()->timestamp,
            'nonce' => 'abc',
        ], $overrides)));
    }

    // ------------------------------------------------------------ starting

    public function test_an_admin_is_given_a_consent_url_with_the_scopes_the_product_needs(): void
    {
        $url = $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/integrations/google/authorize')
            ->assertOk()
            ->json('data.url');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        // Offline with a forced consent, or the second connection comes back
        // with an hour of access and no way to renew it.
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);

        // Exactly one scope, and the narrowest that works: the files a person
        // picked in Google's picker and nothing else in their Drive. Asserted
        // as an equality, not a containment, so a second scope added later
        // — which would put the app back in Google's sensitive-scope review —
        // fails here rather than in a review nobody scheduled.
        $this->assertSame(
            'https://www.googleapis.com/auth/drive.file',
            $query['scope'],
        );
        $this->assertStringNotContainsString('spreadsheets', $query['scope']);
        $this->assertStringNotContainsString('metadata', $query['scope']);

        $this->assertNotEmpty($query['state']);
    }

    public function test_a_rep_cannot_start_a_connection(): void
    {
        $this->actingAs($this->t['rep'] ?? $this->t['sales'] ?? $this->t['admin']);

        // Named explicitly rather than relying on a fixture key that may not
        // exist: the point is the policy, so the test makes its own rep.
        $rep = User::factory()->create([
            'tenant_id' => $this->t['tenant']->id,
            'role' => Role::Sales,
        ]);

        $this->actingAs($rep)
            ->postJson('/api/v1/integrations/google/authorize')
            ->assertForbidden();
    }

    public function test_the_platform_says_when_it_is_the_one_not_configured(): void
    {
        config(['integrations.google_sheets.client_id' => null]);

        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/integrations/google/authorize')
            ->assertStatus(503);
    }

    // ------------------------------------------------------------- coming back

    public function test_a_completed_consent_stores_the_grant_against_the_workspace(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'ya29.access',
            'refresh_token' => '1//refresh',
            'expires_in' => 3599,
            'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly',
        ])]);

        $this->get('/oauth/google/callback?code=4/consent&state='.urlencode($this->state()))
            ->assertRedirect('https://app.example.test/integrations?google=connected');

        $connection = Integration::withoutGlobalScopes()
            ->where('tenant_id', $this->t['tenant']->id)
            ->where('provider', 'google_sheets')
            ->first();

        $this->assertNotNull($connection, 'the callback reported success and stored nothing');
        $this->assertSame(Integration::CONNECTED, $connection->status);
        $this->assertSame('1//refresh', $connection->credentials['refresh_token']);
        // The credential that matters is kept; the hour-long one is incidental.
        $this->assertNotEmpty($connection->credentials['expires_at']);
    }

    /**
     * The grant that works until lunchtime.
     *
     * Google returns a refresh token only on a first consent for a client and
     * account. Without one the connection looks perfect for an hour and then
     * cannot be renewed, so it is refused here with a reason the screen can
     * turn into an instruction.
     */
    public function test_a_consent_with_no_refresh_token_is_refused_rather_than_stored(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'ya29.access',
            'expires_in' => 3599,
        ])]);

        $this->get('/oauth/google/callback?code=4/consent&state='.urlencode($this->state()))
            ->assertRedirect('https://app.example.test/integrations?google=error&reason=no_refresh_token');

        $this->assertSame(0, Integration::withoutGlobalScopes()->where('provider', 'google_sheets')->count());
    }

    public function test_a_tampered_state_writes_nothing(): void
    {
        Http::fake();

        $this->get('/oauth/google/callback?code=4/consent&state=not-a-real-state')
            ->assertRedirect('https://app.example.test/integrations?google=error&reason=invalid_state');

        Http::assertNothingSent();
        $this->assertSame(0, Integration::withoutGlobalScopes()->where('provider', 'google_sheets')->count());
    }

    public function test_an_expired_consent_is_refused(): void
    {
        Http::fake();

        $state = $this->state(['issued' => now()->subHour()->timestamp]);

        $this->get('/oauth/google/callback?code=4/consent&state='.urlencode($state))
            ->assertRedirect('https://app.example.test/integrations?google=error&reason=invalid_state');

        Http::assertNothingSent();
    }

    public function test_pressing_cancel_on_googles_screen_says_so(): void
    {
        $this->get('/oauth/google/callback?error=access_denied&state='.urlencode($this->state()))
            ->assertRedirect('https://app.example.test/integrations?google=error&reason=declined');
    }

    /** Reconnecting fixes a revoked grant rather than producing a second one. */
    public function test_reconnecting_replaces_the_grant_instead_of_adding_one(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'ya29.second',
            'refresh_token' => '1//second',
            'expires_in' => 3599,
        ])]);

        $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'google_sheets',
            'name' => 'Google Sheets',
            'status' => Integration::ERROR,
            'credentials' => ['refresh_token' => '1//first'],
            'is_active' => true,
        ]));

        $this->get('/oauth/google/callback?code=4/again&state='.urlencode($this->state()))
            ->assertRedirect('https://app.example.test/integrations?google=connected');

        $connections = Integration::withoutGlobalScopes()->where('provider', 'google_sheets')->get();

        $this->assertCount(1, $connections);
        $this->assertSame('1//second', $connections->first()->credentials['refresh_token']);
        $this->assertSame(Integration::CONNECTED, $connections->first()->status);
    }

    // --------------------------------------------------------- letting go

    public function test_disconnecting_hands_the_grant_back_and_forgets_it(): void
    {
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response('', 200)]);

        $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'google_sheets',
            'name' => 'Google Sheets',
            'status' => Integration::CONNECTED,
            'credentials' => ['refresh_token' => '1//revoke-me'],
            'is_active' => true,
        ]));

        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/integrations/google/disconnect')
            ->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/revoke'));

        $connection = Integration::withoutGlobalScopes()->where('provider', 'google_sheets')->first();

        $this->assertSame([], $connection->credentials);
        $this->assertSame(Integration::NOT_CONNECTED, $connection->status);
    }

    /**
     * Disconnect means disconnected, even when Google cannot be reached.
     *
     * The alternative is a workspace that pressed the button and is still
     * connected, which is the one outcome nobody expects.
     */
    public function test_our_copy_is_dropped_even_if_google_is_unreachable(): void
    {
        Http::fake(fn () => throw new \RuntimeException('network down'));

        $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'google_sheets',
            'name' => 'Google Sheets',
            'status' => Integration::CONNECTED,
            'credentials' => ['refresh_token' => '1//revoke-me'],
            'is_active' => true,
        ]));

        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/integrations/google/disconnect')
            ->assertOk();

        $this->assertSame(
            [],
            Integration::withoutGlobalScopes()->where('provider', 'google_sheets')->first()->credentials,
        );
    }

    public function test_the_redirect_uri_is_the_one_google_will_be_told_about(): void
    {
        config(['app.url' => 'https://api.example.test']);

        $this->assertSame('https://api.example.test/oauth/google/callback', app(GoogleOAuth::class)->redirectUri());
    }

    // ------------------------------------------------------------ picker

    private function connected(): Integration
    {
        return $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'google_sheets',
            'name' => 'Google Sheets',
            'status' => Integration::CONNECTED,
            'credentials' => [
                'access_token' => 'ya29.live',
                'refresh_token' => '1//refresh',
                'expires_at' => now()->addHour()->toIso8601String(),
            ],
            'is_active' => true,
        ]));
    }

    public function test_the_picker_is_given_a_token_and_the_keys_it_needs(): void
    {
        config([
            'integrations.google_sheets.picker_api_key' => 'AIza-picker-key',
            'integrations.google_sheets.picker_app_id' => '123456789012',
        ]);

        $this->connected();

        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/integrations/google/picker')
            ->assertOk()
            ->assertJsonPath('data.access_token', 'ya29.live')
            ->assertJsonPath('data.developer_key', 'AIza-picker-key')
            // The project number, which is what makes the picked file part of the grant.
            ->assertJsonPath('data.app_id', '123456789012');
    }

    public function test_the_picker_says_so_when_the_platform_has_no_picker_key(): void
    {
        config(['integrations.google_sheets.picker_api_key' => null]);

        $this->connected();

        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/integrations/google/picker')
            ->assertStatus(503);
    }

    public function test_there_is_no_picker_without_a_connected_account(): void
    {
        config([
            'integrations.google_sheets.picker_api_key' => 'k',
            'integrations.google_sheets.picker_app_id' => '1',
        ]);

        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/integrations/google/picker')
            ->assertStatus(422)
            ->assertJsonPath('errors.connection.0', 'sheets.not_connected');
    }

    public function test_a_rep_is_not_handed_a_token(): void
    {
        config([
            'integrations.google_sheets.picker_api_key' => 'k',
            'integrations.google_sheets.picker_app_id' => '1',
        ]);

        $this->connected();

        $rep = User::factory()->create([
            'tenant_id' => $this->t['tenant']->id,
            'role' => Role::Sales,
        ]);

        $this->actingAs($rep)->postJson('/api/v1/integrations/google/picker')->assertForbidden();
    }

    /**
     * A file the person did not pick is Google refusing, not a typo.
     *
     * Under `drive.file` an id outside the grant answers 404 or 403. The screen
     * has to say "choose it in the picker", because "not found" sends somebody
     * to check a link that is perfectly fine.
     */
    public function test_a_spreadsheet_that_was_not_picked_is_reported_as_such(): void
    {
        $this->connected();

        Http::fake(['sheets.googleapis.com/*' => Http::response(['error' => ['code' => 404]], 404)]);

        $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/integrations/sheets/tabs?spreadsheet_id=not-picked')
            ->assertStatus(422)
            ->assertJsonPath('errors.sheet.0', 'sheets.not_picked');
    }

    public function test_the_connection_state_says_whether_a_file_can_be_chosen(): void
    {
        config([
            'integrations.google_sheets.picker_api_key' => null,
            'integrations.google_sheets.picker_app_id' => null,
        ]);

        $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/integrations/sheets')
            ->assertOk()
            ->assertJsonPath('data.connection.picker_configured', false);
    }
}
