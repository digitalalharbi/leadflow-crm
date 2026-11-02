<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\EmailDelivery;
use App\Models\Invitation;
use App\Models\Lead;
use App\Models\PasswordLink;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How people get into a company and what they see there: invitations with their own password, one-use reset links, a
 * person in two companies moving between them, and project access inside one company.
 */
class TeamAccessTest extends TestCase
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

    /** The token the email carried — read back out of the email that was queued, as a person would click it. */
    private function linkFromEmail(string $email, string $path): string
    {
        $html = EmailDelivery::withoutGlobalScopes()->where('to_email', $email)->latest('id')->value('html');
        $this->assertNotNull($html, "No email was queued for {$email}.");
        // An invitation goes out as the short /i/ link.
        $pattern = '#/'.($path === 'invite' ? 'i' : $path).'/([A-Za-z0-9]{48})#';
        $this->assertMatchesRegularExpression($pattern, $html);
        preg_match($pattern, $html, $m);

        return $m[1];
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_an_invited_person_chooses_their_own_password_and_lands_in_the_company(): void
    {
        $this->actingAs($this->a['admin'])->postJson('/api/v1/invitations', ['name' => 'Rana', 'email' => 'Rana@Example.test', 'role' => 'sales'])
            ->assertCreated()->assertJsonPath('data.invitation.email', 'rana@example.test');

        // The email carries a link, never a password; the database keeps only the hash.
        $token = $this->linkFromEmail('rana@example.test', 'invite');
        $this->assertNull(Invitation::withoutGlobalScopes()->where('token_hash', $token)->first());

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/public/invitations/{$token}")->assertOk()
            ->assertJsonPath('data.company', 'Alpha Realty')->assertJsonPath('data.has_account', false);

        $this->postJson("/api/v1/public/invitations/{$token}/accept", ['name' => 'Rana', 'password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable();
        $session = $this->postJson("/api/v1/public/invitations/{$token}/accept", ['name' => 'Rana', 'password' => 'a-good-pass', 'password_confirmation' => 'a-good-pass'])
            ->assertCreated()->assertJsonPath('data.user.email', 'rana@example.test');

        $user = User::where('email', 'rana@example.test')->first();
        $this->assertSame($this->a['tenant']->id, $user->tenant_id);
        $this->assertNotNull($user->email_verified_at);
        $this->withHeaders($this->bearer($session->json('data.token')))->getJson('/api/v1/leads')->assertOk();

        // One use only.
        $this->postJson("/api/v1/public/invitations/{$token}/accept", ['name' => 'X', 'password' => 'a-good-pass', 'password_confirmation' => 'a-good-pass'])->assertNotFound();
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('tenant_id', $this->a['tenant']->id)->where('action', 'invitation.accepted')->exists());
    }

    public function test_resending_kills_the_old_link_and_revoking_kills_the_invitation(): void
    {
        $id = $this->actingAs($this->a['admin'])->postJson('/api/v1/invitations', ['email' => 'late@example.test', 'role' => 'sales'])->json('data.invitation.id');
        $first = $this->linkFromEmail('late@example.test', 'invite');

        $this->actingAs($this->a['admin'])->postJson("/api/v1/invitations/{$id}/resend")->assertOk();
        $second = $this->linkFromEmail('late@example.test', 'invite');
        $this->assertNotSame($first, $second);

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/public/invitations/{$first}")->assertNotFound();
        $this->getJson("/api/v1/public/invitations/{$second}")->assertOk();

        $this->actingAs($this->a['admin'])->deleteJson("/api/v1/invitations/{$id}")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/public/invitations/{$second}")->assertNotFound();
    }

    public function test_an_invitation_expires(): void
    {
        $this->actingAs($this->a['admin'])->postJson('/api/v1/invitations', ['email' => 'slow@example.test', 'role' => 'sales'])->assertCreated();
        $token = $this->linkFromEmail('slow@example.test', 'invite');

        $this->travel(Invitation::DAYS + 1)->days();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/public/invitations/{$token}")->assertNotFound();
    }

    public function test_invitations_are_a_companys_own(): void
    {
        $id = $this->actingAs($this->a['admin'])->postJson('/api/v1/invitations', ['email' => 'mine@example.test', 'role' => 'sales'])->json('data.invitation.id');

        $this->actingAs($this->b['admin'])->getJson('/api/v1/invitations')->assertOk()->assertJsonCount(0, 'data.invitations');
        $this->actingAs($this->b['admin'])->postJson("/api/v1/invitations/{$id}/resend")->assertNotFound();
        $this->actingAs($this->b['admin'])->deleteJson("/api/v1/invitations/{$id}")->assertNotFound();

        // A rep cannot invite.
        $rep = $this->makeUser($this->a['tenant'], Role::Sales);
        $this->actingAs($rep)->postJson('/api/v1/invitations', ['email' => 'x@example.test', 'role' => 'admin'])->assertForbidden();
    }

    public function test_a_pending_invitation_holds_a_seat(): void
    {
        $this->putOnPlan($this->a['tenant'], 'starter'); // 3 seats, admin holds one
        $this->actingAs($this->a['admin'])->postJson('/api/v1/invitations', ['email' => 'one@example.test', 'role' => 'sales'])->assertCreated();
        $this->actingAs($this->a['admin'])->postJson('/api/v1/invitations', ['email' => 'two@example.test', 'role' => 'sales'])->assertCreated();
        $this->actingAs($this->a['admin'])->postJson('/api/v1/invitations', ['email' => 'three@example.test', 'role' => 'sales'])
            ->assertUnprocessable()->assertJsonValidationErrors('seats');

        // Accepting a held seat takes no new one.
        $token = $this->linkFromEmail('one@example.test', 'invite');
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/public/invitations/{$token}/accept", ['name' => 'One', 'password' => 'a-good-pass', 'password_confirmation' => 'a-good-pass'])->assertCreated();
    }

    public function test_someone_already_on_mohtam_joins_a_second_company_with_their_own_password_and_switches(): void
    {
        // A's owner set up their account from the emailed link, so their address is proven.
        $this->a['admin']->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($this->b['admin'])->postJson('/api/v1/invitations', ['email' => $this->a['admin']->email, 'role' => 'manager'])->assertCreated();
        $token = $this->linkFromEmail($this->a['admin']->email, 'invite');

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/public/invitations/{$token}")->assertJsonPath('data.has_account', true);
        $this->postJson("/api/v1/public/invitations/{$token}/accept", ['password' => 'wrong-password'])->assertUnprocessable();
        $inB = $this->postJson("/api/v1/public/invitations/{$token}/accept", ['password' => 'password123'])->assertCreated();
        $tokenB = $inB->json('data.token');

        // From B, the switcher lists both companies; the server decides which.
        $this->app['auth']->forgetGuards();
        $list = $this->withHeaders($this->bearer($tokenB))->getJson('/api/v1/auth/workspaces')->assertOk()->json('data.workspaces');
        $this->assertCount(2, $list);

        // A company this person does not belong to cannot be switched into, by any id.
        $c = $this->provisionTenant('Gamma Realty');
        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->bearer($tokenB))->postJson("/api/v1/auth/workspaces/{$c['tenant']->uuid}/switch")->assertNotFound();

        $this->app['auth']->forgetGuards();
        $switched = $this->withHeaders($this->bearer($tokenB))->postJson("/api/v1/auth/workspaces/{$this->a['tenant']->uuid}/switch")->assertOk();
        $this->assertSame('admin', $switched->json('data.user.role'));

        // And each company's data stays its own after the switch.
        $this->asTenant($this->b['tenant'], fn () => Lead::create(['full_name' => 'Beta Only', 'phone' => '0522222222', 'phone_normalized' => '+966522222222']));
        $this->app['auth']->forgetGuards();
        $names = collect($this->withHeaders($this->bearer($switched->json('data.token')))->getJson('/api/v1/leads')->json('data'))->pluck('full_name');
        $this->assertNotContains('Beta Only', $names);
    }

    public function test_an_unproven_account_cannot_be_used_to_reach_another_company(): void
    {
        $this->a['admin']->forceFill(['email_verified_at' => now()])->save();

        // B's admin creates an account with A's admin's email and a password B knows.
        $planted = $this->makeUser($this->b['tenant'], Role::Sales, ['email' => $this->a['admin']->email, 'password' => 'attacker-pass']);
        $this->assertNull($planted->fresh()->email_verified_at);

        $token = $this->postJson('/api/v1/auth/login', ['email' => $this->a['admin']->email, 'password' => 'attacker-pass'])->assertOk()->json('data.token');
        $this->app['auth']->forgetGuards();

        $this->withHeaders($this->bearer($token))->getJson('/api/v1/auth/workspaces')->assertOk()->assertJsonCount(1, 'data.workspaces');
        $this->withHeaders($this->bearer($token))->postJson("/api/v1/auth/workspaces/{$this->a['tenant']->uuid}/switch")->assertNotFound();
    }

    public function test_a_forgotten_password_is_reset_by_a_one_hour_one_use_link(): void
    {
        $email = $this->a['admin']->email;

        // The same answer for an address nobody has.
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.test'])->assertOk();
        $this->assertSame(0, EmailDelivery::withoutGlobalScopes()->where('to_email', 'nobody@example.test')->count());

        $this->postJson('/api/v1/auth/password/forgot', ['email' => $email])->assertOk();
        $token = $this->linkFromEmail($email, 'set-password');
        $this->assertNull(PasswordLink::where('token_hash', $token)->first());

        $old = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'password123'])->json('data.token');

        $this->getJson("/api/v1/auth/password/links/{$token}")->assertOk()->assertJsonPath('data.purpose', 'reset');
        $this->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])->assertOk()->assertJsonStructure(['data' => ['token']]);

        // Every older session ended; the old password no longer works; the link is spent.
        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->bearer($old))->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'password123'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'brand-new-pass'])->assertOk();
        $this->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => 'again-again', 'password_confirmation' => 'again-again'])->assertUnprocessable();
    }

    public function test_a_reset_link_expires_after_an_hour(): void
    {
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $this->a['admin']->email])->assertOk();
        $token = $this->linkFromEmail($this->a['admin']->email, 'set-password');

        $this->travel(61)->minutes();
        $this->getJson("/api/v1/auth/password/links/{$token}")->assertNotFound();
    }

    public function test_a_new_company_owner_sets_their_own_password_from_a_setup_link(): void
    {
        $owner = $this->makePlatformOwner();

        $this->actingAs($owner)->postJson('/api/v1/super-admin/tenants', [
            'company_name' => 'Delta Homes', 'admin_name' => 'Delta Owner', 'admin_email' => 'owner@delta.test',
        ])->assertCreated()->assertJsonPath('data.admin.setup_link_sent', true);

        $token = $this->linkFromEmail('owner@delta.test', 'set-password');
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/auth/password/links/{$token}")->assertOk()->assertJsonPath('data.purpose', 'setup');
        $this->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => 'owner-chosen', 'password_confirmation' => 'owner-chosen'])
            ->assertOk()->assertJsonPath('data.user.email', 'owner@delta.test');
    }

    public function test_a_manager_limited_to_some_projects_cannot_open_another_by_id(): void
    {
        [$p1, $p2] = $this->asTenant($this->a['tenant'], fn () => [Project::create(['name' => 'Tower One']), Project::create(['name' => 'Tower Two'])]);
        $this->asTenant($this->a['tenant'], fn () => [
            Lead::create(['full_name' => 'In One', 'phone' => '0501', 'phone_normalized' => '+9665010001', 'project_id' => $p1->id]),
            Lead::create(['full_name' => 'In Two', 'phone' => '0502', 'phone_normalized' => '+9665010002', 'project_id' => $p2->id]),
        ]);

        $manager = $this->makeUser($this->a['tenant'], Role::Manager, ['all_projects' => false]);
        $manager->projects()->sync([$p1->id]);

        $this->actingAs($manager)->getJson("/api/v1/projects/{$p1->uuid}")->assertOk();
        $this->actingAs($manager)->getJson("/api/v1/projects/{$p2->uuid}")->assertNotFound();
        $names = collect($this->actingAs($manager)->getJson('/api/v1/leads')->json('data'))->pluck('full_name');
        $this->assertEquals(['In One'], $names->all());

        // Fail closed: limited with nothing given means nothing.
        $empty = $this->makeUser($this->a['tenant'], Role::SalesManager, ['all_projects' => false]);
        $this->assertSame(0, (int) $this->actingAs($empty)->getJson('/api/v1/leads')->json('meta.total'));

        // An admin always sees every project.
        $this->actingAs($this->a['admin'])->getJson("/api/v1/projects/{$p2->uuid}")->assertOk();
    }

    public function test_a_rep_sees_only_their_projects_and_cannot_open_another_by_id(): void
    {
        [$p1, $p2] = $this->asTenant($this->a['tenant'], fn () => [Project::create(['name' => 'Tower One']), Project::create(['name' => 'Tower Two'])]);
        $rep = $this->makeUser($this->a['tenant'], Role::Sales, ['all_projects' => false]);
        $this->asTenant($this->a['tenant'], fn () => Lead::create(['full_name' => 'Mine', 'phone' => '0503', 'phone_normalized' => '+9665010003', 'project_id' => $p1->id, 'assigned_to' => $rep->id]));

        $this->actingAs($rep)->getJson("/api/v1/projects/{$p1->uuid}")->assertOk();
        $this->actingAs($rep)->getJson("/api/v1/projects/{$p2->uuid}")->assertNotFound();
        $ids = collect($this->actingAs($rep)->getJson('/api/v1/projects')->json('data.projects') ?? $this->actingAs($rep)->getJson('/api/v1/projects')->json('data'))->pluck('id');
        $this->assertContains($p1->uuid, $ids);
        $this->assertNotContains($p2->uuid, $ids);
    }

    public function test_the_team_list_shows_when_each_person_was_last_active(): void
    {
        $rep = $this->makeUser($this->a['tenant'], Role::Sales);
        $token = $this->postJson('/api/v1/auth/login', ['email' => $rep->email, 'password' => 'password123'])->json('data.token');
        $this->withHeaders($this->bearer($token))->getJson('/api/v1/leads')->assertOk();
        $this->app['auth']->forgetGuards();

        $row = collect($this->actingAs($this->a['admin'])->getJson('/api/v1/users')->json('data.users'))->firstWhere('id', $rep->uuid);
        $this->assertNotNull($row['last_active_at']);
    }
}
