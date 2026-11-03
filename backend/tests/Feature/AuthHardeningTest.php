<?php

namespace Tests\Feature;

use App\Domain\Auth\EmailVerification;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\EmailDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Companies are linked by a proven email, so everything that could prove the wrong thing is here: a typed address, a typed
 * password, a reset that reached one inbox. Plus what ends a session, and what an email's body may leave lying around.
 */
class AuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeMailer();
        $this->a = $this->provisionTenant('Alpha Realty');
        $this->b = $this->provisionTenant('Beta Realty');
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function signIn(User $user, string $password = 'password123'): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $password])->assertOk()->json('data.token');
    }

    public function test_typing_someone_elses_email_on_your_own_account_reaches_none_of_their_companies(): void
    {
        $victim = $this->a['admin']->forceFill(['email_verified_at' => now()]);
        $victim->save();
        $rep = $this->makeUser($this->b['tenant'], Role::Sales);
        $rep->forceFill(['email_verified_at' => now()])->save();
        $token = $this->signIn($rep);

        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->bearer($token))->patchJson("/api/v1/users/{$rep->uuid}", ['email' => $victim->email, 'current_password' => 'password123'])->assertOk();

        $this->assertNull($rep->fresh()->email_verified_at);
        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->bearer($token))->getJson('/api/v1/auth/workspaces')->assertOk()->assertJsonCount(1, 'data.workspaces');
        $this->withHeaders($this->bearer($token))->postJson("/api/v1/auth/workspaces/{$this->a['tenant']->uuid}/switch")->assertNotFound();
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'user.email_changed')->where('tenant_id', $this->b['tenant']->id)->exists());
    }

    public function test_saving_the_same_email_proves_nothing_away_and_ends_no_session(): void
    {
        $rep = $this->makeUser($this->b['tenant'], Role::Sales);
        $rep->forceFill(['email_verified_at' => now()])->save();
        $rep->createToken('spa-other');

        $this->actingAs($this->b['admin'])->patchJson("/api/v1/users/{$rep->uuid}", ['email' => strtoupper($rep->email), 'name' => 'Same'])->assertOk();

        $this->assertNotNull($rep->fresh()->email_verified_at);
        $this->assertSame(1, $rep->tokens()->count());
    }

    public function test_an_admin_changing_someones_email_ends_their_sessions(): void
    {
        $rep = $this->makeUser($this->b['tenant'], Role::Sales);
        $rep->forceFill(['email_verified_at' => now()])->save();
        $rep->createToken('spa-other');

        $this->actingAs($this->b['admin'])->patchJson("/api/v1/users/{$rep->uuid}", ['email' => 'moved@example.test'])->assertOk();

        $this->assertNull($rep->fresh()->email_verified_at);
        $this->assertSame(0, $rep->tokens()->count());
    }

    public function test_nobody_sets_a_password_through_the_team_screen_not_an_admin_not_the_person(): void
    {
        $rep = $this->makeUser($this->b['tenant'], Role::Sales);
        $before = $rep->password;

        $this->actingAs($this->b['admin'])->patchJson("/api/v1/users/{$rep->uuid}", ['password' => 'admin-chose-this'])->assertOk();
        $this->assertSame($before, $rep->fresh()->password);

        $this->actingAs($rep)->patchJson("/api/v1/users/{$rep->uuid}", ['password' => 'without-the-old-one'])->assertOk();
        $this->assertSame($before, $rep->fresh()->password);
    }

    public function test_a_reset_proves_the_account_in_use_not_a_row_another_company_planted(): void
    {
        $real = $this->a['admin'];
        $real->forceFill(['last_login_at' => now()])->save();
        $planted = $this->makeUser($this->b['tenant'], Role::Sales, ['email' => $real->email]);

        $this->postJson('/api/v1/auth/password/forgot', ['email' => $real->email])->assertOk();
        preg_match('#/reset-password\?token=([A-Za-z0-9]{48})#', (string) EmailDelivery::withoutGlobalScopes()->where('to_email', $real->email)->latest('id')->value('html'), $m);

        $this->postJson('/api/v1/auth/password/reset', ['token' => $m[1], 'password' => 'a-new-password', 'password_confirmation' => 'a-new-password'])->assertOk();

        $this->assertNotNull($real->fresh()->email_verified_at);
        $this->assertNull($planted->fresh()->email_verified_at);
    }

    public function test_a_forgotten_password_cannot_flood_an_inbox_and_answers_the_same_either_way(): void
    {
        $email = $this->a['admin']->email;
        $known = $this->postJson('/api/v1/auth/password/forgot', ['email' => $email])->assertOk()->json();
        $unknown = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.test'])->assertOk()->json();
        $this->assertSame($known, $unknown);

        for ($i = 0; $i < 4; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])->postJson('/api/v1/auth/password/forgot', ['email' => $email]);
        }

        $this->assertSame(3, EmailDelivery::withoutGlobalScopes()->where('to_email', $email)->count());
    }

    public function test_one_address_is_limited_from_anywhere(): void
    {
        config(['integrations.login.per_account_per_quarter_hour' => 3]);
        $body = ['email' => 'target@example.test', 'password' => 'guess'];

        foreach (range(1, 3) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.1.0.{$i}"])->postJson('/api/v1/auth/login', $body)->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.9'])->postJson('/api/v1/auth/login', $body)->assertStatus(429);
    }

    public function test_the_platforms_second_factor_cannot_be_replaced_with_only_a_session(): void
    {
        $owner = $this->makePlatformOwner();
        $owner->forceFill(['mfa_secret' => 'ORIGINALSECRET', 'mfa_enabled_at' => now()])->save();
        $token = $owner->createToken('platform', ['platform'], now()->addHour())->plainTextToken;

        $this->withHeaders($this->bearer($token))->postJson('/api/v1/platform/auth/mfa/setup')->assertUnprocessable();

        $this->assertSame('ORIGINALSECRET', $owner->fresh()->mfa_secret);
    }

    public function test_moving_to_another_company_ends_the_session_being_left(): void
    {
        $person = $this->a['admin'];
        $person->forceFill(['email_verified_at' => now()])->save();
        $other = $this->makeUser($this->b['tenant'], Role::Manager, ['email' => $person->email]);
        $other->forceFill(['email_verified_at' => now()])->save();
        $token = $this->signIn($person);

        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->bearer($token))->postJson("/api/v1/auth/workspaces/{$this->b['tenant']->uuid}/switch")->assertOk()->assertJsonPath('data.user.role', 'manager');

        $this->assertSame(0, $person->tokens()->count());
    }

    public function test_a_session_ends_after_thirty_days(): void
    {
        $token = $this->signIn($this->a['admin']);

        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->bearer($token))->getJson('/api/v1/auth/me')->assertOk();

        $this->travel(31)->days();
        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->bearer($token))->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_signing_out_of_other_devices_keeps_this_one_and_leaves_a_record(): void
    {
        $user = $this->a['admin'];
        $here = $this->signIn($user);
        $this->signIn($user);
        $this->signIn($user);
        $this->assertSame(3, $user->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->bearer($here))->postJson('/api/v1/auth/security/sessions/end-others')->assertOk()->assertJsonPath('data.ended', 2);

        $this->assertSame(1, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->bearer($here))->getJson('/api/v1/auth/me')->assertOk();
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'auth.other_sessions_ended')->exists());

        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->bearer($here))->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'auth.logout')->exists());
    }

    public function test_an_address_is_proven_by_its_own_link_for_that_account_alone(): void
    {
        $rep = $this->makeUser($this->b['tenant'], Role::Sales);
        $this->actingAs($rep)->postJson('/api/v1/auth/email/verification')->assertOk()->assertJsonPath('data.verified', false);

        $html = (string) EmailDelivery::withoutGlobalScopes()->where('to_email', $rep->email)->latest('id')->value('html');
        $this->assertStringContainsString('/verify-email?token=', $html);
        $token = html_entity_decode(explode('"', explode('/verify-email?token=', $html)[1])[0]);

        // Not a password link, and a tampered one is nothing.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => 'a-new-password', 'password_confirmation' => 'a-new-password'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/email/verify', ['token' => $token.'x', 'password' => 'password123'])->assertUnprocessable();
        $this->assertNull($rep->fresh()->email_verified_at);

        $this->postJson('/api/v1/auth/email/verify', ['token' => $token, 'password' => 'not-theirs'])->assertUnprocessable();
        $this->assertNull($rep->fresh()->email_verified_at);
        $this->postJson('/api/v1/auth/email/verify', ['token' => $token, 'password' => 'password123'])->assertOk()->assertJsonPath('data.verified', true);
        $at = $rep->fresh()->email_verified_at;
        $this->assertNotNull($at);

        // Using it again changes nothing.
        $this->postJson('/api/v1/auth/email/verify', ['token' => $token, 'password' => 'password123'])->assertOk();
        $this->assertEquals($at, $rep->fresh()->email_verified_at);
    }

    public function test_a_link_stops_working_when_the_address_changes_or_time_runs_out(): void
    {
        $rep = $this->makeUser($this->b['tenant'], Role::Sales);
        $token = EmailVerification::token($rep);

        $rep->forceFill(['email' => 'moved@example.test'])->save();
        $this->postJson('/api/v1/auth/email/verify', ['token' => $token, 'password' => 'password123'])->assertUnprocessable();

        $fresh = EmailVerification::token($rep);
        $this->travel(EmailVerification::HOURS + 1)->hours();
        $this->postJson('/api/v1/auth/email/verify', ['token' => $fresh, 'password' => 'password123'])->assertUnprocessable();
    }

    public function test_an_emails_body_is_not_readable_at_rest_and_old_rows_still_read(): void
    {
        $email = $this->a['admin']->email;
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $email])->assertOk();

        $row = DB::table('email_deliveries')->where('to_email', $email)->latest('id')->first();
        $this->assertStringNotContainsString('reset-password', $row->html);
        $this->assertStringContainsString('reset-password', (string) EmailDelivery::withoutGlobalScopes()->whereKey($row->id)->value('html'));

        DB::table('email_deliveries')->where('id', $row->id)->update(['html' => '<p>from before</p>']);
        $this->assertSame('<p>from before</p>', EmailDelivery::withoutGlobalScopes()->whereKey($row->id)->value('html'));
    }

    public function test_account_emails_speak_the_persons_language_and_direction(): void
    {
        $this->a['admin']->forceFill(['locale' => 'en'])->save();
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $this->a['admin']->email])->assertOk();
        $english = (string) EmailDelivery::withoutGlobalScopes()->where('to_email', $this->a['admin']->email)->latest('id')->value('html');
        $this->assertStringContainsString('dir="ltr"', $english);
        $this->assertStringContainsString('Set a new password', $english);

        $this->postJson('/api/v1/auth/password/forgot', ['email' => $this->b['admin']->email])->assertOk();
        $arabic = (string) EmailDelivery::withoutGlobalScopes()->where('to_email', $this->b['admin']->email)->latest('id')->value('html');
        $this->assertStringContainsString('dir="rtl"', $arabic);
    }

    public function test_following_the_runbook_switches_the_mail_provider(): void
    {
        $read = fn (string $setting) => (function () use ($setting) {
            putenv("MAIL_MAILER={$setting}");
            $_ENV['MAIL_MAILER'] = $setting;
            $_SERVER['MAIL_MAILER'] = $setting;

            return (require base_path('config/leadflow_mail.php'))['provider'];
        })();
        $was = env('MAIL_MAILER');

        $this->assertSame('resend', $read('resend'));
        $this->assertSame('ses', $read('ses'));
        $this->assertSame('log', $read('array'));

        $read((string) $was);
    }
}
