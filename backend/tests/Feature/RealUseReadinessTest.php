<?php

namespace Tests\Feature;

use App\DataTransferObjects\IncomingLead;
use App\Domain\Auth\EmailVerification;
use App\Domain\Mail\AccountEmail;
use App\Domain\Privacy\SuppressionList;
use App\Enums\IntegrationProvider;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Events\LeadSignal;
use App\Models\EmailDelivery;
use App\Models\Integration;
use App\Models\Invitation;
use App\Models\Lead;
use App\Models\PasswordLink;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Leads\LeadIngestionService;
use App\Support\Totp;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** What stands between the product and being used for real: getting people in with or without mail, and nothing opening by accident. */
class RealUseReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_nobody_can_create_a_company_from_outside(): void
    {
        foreach (['/api/v1/auth/register', '/api/v1/register', '/api/v1/signup', '/api/v1/auth/signup', '/api/v1/public/signup', '/api/v1/tenants'] as $path) {
            $this->postJson($path, ['company_name' => 'x', 'email' => 'x@x.test', 'password' => 'a-strong-pass'])->assertNotFound();
        }

        $open = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => in_array('POST', $r->methods(), true)
            && preg_match('/(tenant|signup|register)/i', $r->uri())
            && ! in_array('auth:sanctum', $r->gatherMiddleware(), true));

        $this->assertSame([], $open->map->uri()->values()->all());
    }

    public function test_the_first_admin_is_made_at_a_hidden_prompt_and_only_once(): void
    {
        $this->artisan('mohtam:bootstrap-admin', ['--email' => 'Owner@Mohtam.test', '--name' => 'Owner'])
            ->expectsQuestion('Password (hidden, at least 12 characters)', 'a-long-enough-pass')
            ->expectsQuestion('Password again', 'a-long-enough-pass')
            ->doesntExpectOutputToContain('a-long-enough-pass')
            ->assertSuccessful();

        $admin = User::whereNull('tenant_id')->where('email', 'owner@mohtam.test')->sole();
        $this->assertTrue(Hash::check('a-long-enough-pass', $admin->password));
        $this->assertNotNull($admin->email_verified_at);

        // Again: nothing changes, nothing is asked.
        $this->artisan('mohtam:bootstrap-admin', ['--email' => 'owner@mohtam.test'])->assertSuccessful();
        $this->assertSame(1, User::whereNull('tenant_id')->where('email', 'owner@mohtam.test')->count());
        $this->assertTrue(Hash::check('a-long-enough-pass', $admin->fresh()->password));
    }

    public function test_a_short_or_mistyped_first_password_makes_no_account(): void
    {
        $this->artisan('mohtam:bootstrap-admin', ['--email' => 'a@mohtam.test', '--name' => 'A'])
            ->expectsQuestion('Password (hidden, at least 12 characters)', 'short')->assertFailed();
        $this->artisan('mohtam:bootstrap-admin', ['--email' => 'a@mohtam.test', '--name' => 'A'])
            ->expectsQuestion('Password (hidden, at least 12 characters)', 'a-long-enough-pass')
            ->expectsQuestion('Password again', 'a-different-pass!')->assertFailed();

        $this->assertSame(0, User::where('email', 'a@mohtam.test')->count());
    }

    public function test_the_older_command_no_longer_takes_or_prints_a_password(): void
    {
        $this->assertFalse($this->app->make(Kernel::class)->all()['leadflow:create-super-admin']->getDefinition()->hasOption('password'));
    }

    public function test_with_no_mail_provider_the_inviter_is_handed_the_link_and_it_proves_no_inbox(): void
    {
        config(['leadflow_mail.provider' => 'log']);
        $other = $this->provisionTenant('Other', ['subdomain' => 'other'])['tenant'];
        $victim = $this->makeUser($other, Role::Admin, ['email' => 'victim@x.test', 'password' => 'victims-password']);
        $victim->forceFill(['email_verified_at' => now()])->save();

        $tenant = $this->provisionTenant('Mine', ['subdomain' => 'mine'])['tenant'];
        $admin = $this->makeUser($tenant, Role::Admin, ['email' => 'admin@mine.test']);

        $link = $this->actingAs($admin)->postJson('/api/v1/invitations', ['email' => 'victim@x.test', 'role' => 'sales'])->assertCreated()->json('data.link');
        $this->assertNotEmpty($link);
        $token = basename($link);
        $this->app['auth']->forgetGuards();

        // It says nothing about an account elsewhere, joins to none, and the new row's address stays unproven.
        $this->getJson("/api/v1/public/invitations/{$token}")->assertOk()->assertJsonPath('data.has_account', false);
        $this->postJson("/api/v1/public/invitations/{$token}/accept", ['name' => 'Rep', 'password' => 'attackers-pass', 'password_confirmation' => 'attackers-pass'])->assertCreated();
        $this->assertNull(User::where('tenant_id', $tenant->id)->where('email', 'victim@x.test')->sole()->email_verified_at);
        $this->assertTrue(Hash::check('victims-password', $victim->fresh()->password));

        $session = $this->postJson('/api/v1/auth/login', ['email' => 'victim@x.test', 'password' => 'attackers-pass', 'tenant' => 'mine'])->assertOk()->json('data.token');
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $ids = collect($this->withHeaders(['Authorization' => 'Bearer '.$session])->getJson('/api/v1/auth/workspaces')->assertOk()->json('data.workspaces'))->pluck('id');
        $this->assertNotContains($other->uuid, $ids->all(), 'A handed link must not reach the real owner of the address.');
    }

    public function test_with_a_mail_provider_no_link_is_handed_over(): void
    {
        $this->fakeMailer();
        config(['leadflow_mail.provider' => 'resend', 'leadflow_mail.resend.key' => 'test-key']);
        $tenant = $this->provisionTenant()['tenant'];
        $admin = $this->makeUser($tenant, Role::Admin);

        $body = $this->actingAs($admin)->postJson('/api/v1/invitations', ['email' => 'new@x.test', 'role' => 'sales'])->assertCreated()->json('data');
        $this->assertArrayNotHasKey('link', $body);
        $this->assertNull(Invitation::where('email', 'new@x.test')->sole()->manual_link_at);
    }

    public function test_a_session_not_remembered_ends_within_the_day(): void
    {
        $tenant = $this->provisionTenant()['tenant'];
        $this->makeUser($tenant, Role::Admin, ['email' => 'a@x.test', 'password' => 'a-strong-pass']);

        $this->postJson('/api/v1/auth/login', ['email' => 'a@x.test', 'password' => 'a-strong-pass', 'remember' => false])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'a@x.test', 'password' => 'a-strong-pass'])->assertOk();

        $tokens = User::where('email', 'a@x.test')->sole()->tokens()->orderBy('id')->get();
        $this->assertTrue($tokens[0]->expires_at->lessThanOrEqualTo(now()->addHours(12)->addMinute()));
        $this->assertNull($tokens[1]->expires_at);
    }

    public function test_a_person_hears_about_changes_to_their_account(): void
    {
        $tenant = $this->provisionTenant()['tenant'];
        $user = $this->makeUser($tenant, Role::Admin, ['email' => 'a@x.test', 'password' => 'a-strong-pass']);

        $this->actingAs($user)->postJson('/api/v1/auth/password', ['current_password' => 'a-strong-pass', 'password' => 'another-strong', 'password_confirmation' => 'another-strong'])->assertOk();
        $this->actingAs($user)->patchJson("/api/v1/users/{$user->uuid}", ['email' => 'b@x.test', 'current_password' => 'another-strong'])->assertOk();

        $this->assertSame(1, EmailDelivery::withoutGlobalScopes()->where('kind', 'security')->where('to_email', 'a@x.test')->where('subject', 'like', '%كلمة مرورك%')->count());
        $notice = EmailDelivery::withoutGlobalScopes()->where('kind', 'security')->where('to_email', 'a@x.test')->where('subject', 'like', '%بريد الدخول%')->sole();
        $this->assertStringContainsString('b@x.test', (string) $notice->html);
        $this->assertStringNotContainsString('another-strong', (string) $notice->html);
    }

    public function test_an_inbound_endpoint_stops_on_rotate_and_on_disable(): void
    {
        $tenant = $this->provisionTenant()['tenant'];
        $admin = $this->makeUser($tenant, Role::Admin);
        [$integration] = [$this->asTenant($tenant, fn () => Integration::create(['provider' => IntegrationProvider::GenericWebhook, 'name' => 'W', 'status' => 'connected', 'field_mapping' => []]))];
        [$endpoint, $old] = $this->asTenant($tenant, fn () => WebhookEndpoint::issue($integration->id, 'W'));
        $url = "/api/v1/integrations/webhooks/{$endpoint->key}/leads";
        $lead = ['full_name' => 'A', 'phone' => '0551234567'];

        $new = $this->actingAs($admin)->postJson("/api/v1/webhook-endpoints/{$endpoint->uuid}/rotate")->assertOk()->json('data.secret');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withHeaders(['X-Webhook-Secret' => $old])->postJson($url, $lead)->assertUnauthorized();
        $this->withHeaders(['X-Webhook-Secret' => $new])->postJson($url, $lead)->assertSuccessful();

        $this->actingAs($admin)->deleteJson("/api/v1/webhook-endpoints/{$endpoint->uuid}")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->assertContains($this->withHeaders(['X-Webhook-Secret' => $new])->postJson($url, $lead)->getStatusCode(), [401, 403, 404]);
    }

    public function test_the_first_clients_path_passes_here_and_leaves_nothing_behind(): void
    {
        config(['platform.require_mfa' => true]);
        $tenants = Tenant::withoutGlobalScopes()->count();
        $users = User::withoutGlobalScopes()->count();

        $this->artisan('mohtam:production-smoke')->expectsOutputToContain('FIRST_CLIENT_SMOKE=PASS')->assertSuccessful();

        $this->assertSame($tenants, Tenant::withoutGlobalScopes()->count());
        $this->assertSame($users, User::withoutGlobalScopes()->count());
    }

    // -------------------------------------------- the independent audits' findings

    public function test_a_verify_link_does_not_join_a_strangers_companies_to_an_impostors_row(): void
    {
        $this->fakeMailer();
        $victimCo = $this->provisionTenant('Victim Co')['tenant'];
        $victim = $this->makeUser($victimCo, Role::Admin, ['email' => 'victim@x.test', 'password' => 'victims-password']);
        $victim->forceFill(['email_verified_at' => now()])->save();

        $attackerCo = $this->provisionTenant('Attacker Co')['tenant'];
        $impostor = $this->makeUser($attackerCo, Role::Sales, ['email' => 'attacker@x.test', 'password' => 'attackers-pass']);
        $this->actingAs($impostor)->patchJson("/api/v1/users/{$impostor->uuid}", ['email' => 'victim@x.test', 'current_password' => 'attackers-pass'])->assertOk();

        // The victim presses the link: without the row's password it proves nothing about the row.
        $token = EmailVerification::token($impostor->fresh());
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/email/verify', ['token' => $token, 'password' => 'victims-password'])->assertUnprocessable();
        $this->assertNull($impostor->fresh()->email_verified_at);
    }

    public function test_an_admin_cannot_take_over_a_colleague_through_their_email(): void
    {
        ['tenant' => $tenant, 'admin' => $owner] = $this->provisionTenant();
        $admin = $this->makeUser($tenant, Role::Admin, ['email' => 'second@x.test']);
        $colleague = $this->makeUser($tenant, Role::Sales, ['email' => 'c@x.test']);
        $colleague->forceFill(['last_login_at' => now()])->save();

        $this->actingAs($admin)->patchJson("/api/v1/users/{$colleague->uuid}", ['email' => 'mine@x.test'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->actingAs($admin)->patchJson("/api/v1/users/{$owner->uuid}", ['email' => 'mine@x.test'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame('c@x.test', $colleague->fresh()->email);

        // Their own takes their password: a stolen session is not enough.
        $this->actingAs($admin)->patchJson("/api/v1/users/{$admin->uuid}", ['email' => 'new@x.test'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }

    public function test_a_new_password_reaches_this_persons_other_companies_and_ends_their_sessions(): void
    {
        $this->fakeMailer();
        $a = $this->makeUser($this->provisionTenant('A Co')['tenant'], Role::Admin, ['email' => 'p@x.test', 'password' => 'old-password']);
        $b = $this->makeUser($this->provisionTenant('B Co')['tenant'], Role::Admin, ['email' => 'p@x.test', 'password' => 'old-password']);
        $a->forceFill(['email_verified_at' => now()])->save();
        $b->forceFill(['email_verified_at' => now()])->save();
        $b->createToken('there');

        $this->actingAs($a)->postJson('/api/v1/auth/password', ['current_password' => 'old-password', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertOk();

        $this->assertTrue(Hash::check('new-password', $b->fresh()->password));
        $this->assertSame(0, $b->tokens()->count());
    }

    public function test_confirming_again_hands_out_no_new_recovery_codes(): void
    {
        $user = $this->makeUser($this->provisionTenant()['tenant'], Role::Admin);
        $secret = $this->actingAs($user)->postJson('/api/v1/auth/security/mfa/setup')->assertOk()->json('data.secret');
        $this->actingAs($user)->postJson('/api/v1/auth/security/mfa/confirm', ['code' => Totp::code($secret)])->assertOk();
        $this->actingAs($user->fresh())->postJson('/api/v1/auth/security/mfa/confirm', ['code' => Totp::code($secret)])->assertUnprocessable();
    }

    public function test_a_departing_admins_open_invitations_go_with_them(): void
    {
        ['tenant' => $tenant, 'admin' => $owner] = $this->provisionTenant();
        $leaver = $this->makeUser($tenant, Role::Admin);
        $this->actingAs($leaver)->postJson('/api/v1/invitations', ['email' => 'second-inbox@x.test', 'role' => 'admin'])->assertCreated();
        $this->app['auth']->forgetGuards();

        $this->actingAs($owner)->deleteJson("/api/v1/users/{$leaver->uuid}")->assertOk();

        $this->assertNotNull($this->asTenant($tenant, fn () => Invitation::where('email', 'second-inbox@x.test')->sole())->revoked_at);
    }

    public function test_a_suppressed_address_put_on_a_lead_later_is_still_stopped(): void
    {
        ['tenant' => $tenant] = $this->provisionTenant();
        $this->asTenant($tenant, function () use ($tenant) {
            app(SuppressionList::class)->add($tenant->id, 'email', 'stop@x.test', 'opt_out');
            $lead = Lead::create(['full_name' => 'A', 'phone' => '0551234567', 'phone_normalized' => '+966551234567']);
            $lead->update(['email' => 'STOP@x.test']);
            $this->assertNotNull($lead->fresh()->email_opt_out_at);
        });
    }

    public function test_a_provider_retry_changes_nothing_and_fires_nothing(): void
    {
        ['tenant' => $tenant] = $this->provisionTenant();
        $incoming = fn () => IncomingLead::fromArray(IntegrationProvider::GenericWebhook, ['full_name' => 'A', 'phone' => '0551234567', 'provider_lead_id' => 'sub-1']);
        $service = app(LeadIngestionService::class);
        $lead = $service->ingest($tenant, $incoming())->lead;
        $this->asTenant($tenant, fn () => $lead->fresh()->forceFill(['status' => LeadStatus::Lost])->save());

        Event::fake([LeadSignal::class]);
        $service->ingest($tenant, $incoming());

        Event::assertNotDispatched(LeadSignal::class);
        $fresh = $this->asTenant($tenant, fn () => $lead->fresh());
        $this->assertSame(0, (int) $fresh->re_engaged_count);
        $this->assertSame(LeadStatus::Lost, $fresh->status);
    }

    public function test_someone_limited_to_a_project_writes_no_company_wide_rule_and_hears_no_other_projects_news(): void
    {
        ['tenant' => $tenant] = $this->provisionTenant();
        $limited = $this->makeUser($tenant, Role::Manager);
        $limited->forceFill(['all_projects' => false])->save();
        [$mine, $other] = $this->asTenant($tenant, fn () => [Project::create(['name' => 'Mine']), Project::create(['name' => 'Other'])]);
        $limited->projects()->sync([$mine->id]);

        $this->assertTrue($limited->isProjectScoped());
        $this->assertTrue($limited->reaches($mine->id));
        $this->assertFalse($limited->reaches($other->id));
        $this->actingAs($limited)->postJson('/api/v1/automation/rules', ['name' => 'x'])->assertForbidden();
        $this->actingAs($limited)->getJson('/api/v1/settings/suppressions')->assertForbidden();
    }

    public function test_every_link_and_address_in_an_account_email_is_the_official_one(): void
    {
        config(['integrations.app_url' => 'https://mohtam.pro']);
        ['tenant' => $tenant] = $this->provisionTenant();
        $user = $this->makeUser($tenant, Role::Admin, ['email' => 'p@x.test']);

        $this->assertSame('https://mohtam.pro/reset-password?token=abc', PasswordLink::url('abc'));
        $this->assertStringStartsWith('https://mohtam.pro/verify-email?token=', EmailVerification::url('abc'));
        $this->assertStringStartsWith('https://mohtam.pro/i/', $this->asTenant($tenant, fn () => new Invitation)->url('abc'));
        $this->assertSame('info@mohtam.pro', config('leadflow_mail.reply_to'));
        $this->assertSame('مهتم — Mohtam', config('leadflow_mail.from.name'));

        $mailer = $this->fakeMailer(true);
        app(AccountEmail::class)->verification($user, EmailVerification::token($user), $tenant);
        $this->assertSame('info@mohtam.pro', $mailer->sent[0]->replyTo);
        $this->assertStringContainsString('https://mohtam.pro/verify-email?token=', $mailer->sent[0]->html);
    }

    public function test_no_other_way_to_sign_in_is_offered_until_one_works(): void
    {
        config(['services.google_login.client_id' => null, 'services.google_login.client_secret' => null]);
        $this->getJson('/api/v1/auth/providers')->assertOk()->assertExactJson(['success' => true, 'data' => ['providers' => []], 'message' => null, 'errors' => null]);
    }

    public function test_the_browser_tests_owner_cannot_be_made_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->artisan('leadflow:e2e-platform-owner', ['email' => 'x@x.test'])->assertFailed();
        $this->assertSame(0, User::where('email', 'x@x.test')->count());
    }

    public function test_what_reaches_outside_waits_for_a_proven_address_once_mail_goes_out(): void
    {
        config(['platform.require_verified_email' => true]);
        $user = $this->makeUser($this->provisionTenant()['tenant'], Role::Admin);

        // No provider: nobody could prove anything, so nothing is held back.
        config(['leadflow_mail.provider' => 'log']);
        $this->actingAs($user)->postJson('/api/v1/invitations', ['email' => 'a@x.test', 'role' => 'sales'])->assertCreated();

        $this->fakeMailer();
        $this->actingAs($user)->postJson('/api/v1/invitations', ['email' => 'b@x.test', 'role' => 'sales'])->assertForbidden()->assertJsonPath('data.code', 'email_verification_required');
        $this->actingAs($user)->postJson('/api/v1/api-keys', ['name' => 'k'])->assertForbidden();
        // Everyday work goes on, and so does the way to prove it.
        $this->actingAs($user)->getJson('/api/v1/leads')->assertOk();
        $this->actingAs($user)->postJson('/api/v1/auth/email/verification')->assertOk();

        $user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($user->fresh())->postJson('/api/v1/invitations', ['email' => 'b@x.test', 'role' => 'sales'])->assertCreated();
    }

    public function test_the_platform_owner_is_not_held_back_by_it(): void
    {
        config(['platform.require_verified_email' => true]);
        $this->fakeMailer();
        $owner = $this->makePlatformOwner();

        $this->actingAs($owner)->postJson('/api/v1/super-admin/tenants', ['company_name' => 'New Co', 'admin_name' => 'A', 'admin_email' => 'a@new.test'])->assertCreated();
    }
}
