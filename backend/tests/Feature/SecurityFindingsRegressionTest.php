<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AccessRole;
use App\Models\ApiKey;
use App\Models\Appointment;
use App\Models\AutomationActionLog;
use App\Models\Company;
use App\Models\DataExport;
use App\Models\Lead;
use App\Models\PermissionRule;
use App\Models\Project;
use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * The six findings of the security review of the remaining-gaps batch, each pinned: the hole stays closed and what
 * the feature is for still works.
 */
class SecurityFindingsRegressionTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Company $alpha;

    private Company $beta;

    private Project $alphaProject;

    private Project $betaProject;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->t = $this->provisionTenant('Holding');

        $this->asTenant($this->t['tenant'], function () {
            $this->alpha = Company::create(['name' => 'Alpha', 'is_active' => true]);
            $this->beta = Company::create(['name' => 'Beta', 'is_active' => true]);
            $this->alphaProject = Project::create(['name' => 'A', 'company_id' => $this->alpha->id]);
            $this->betaProject = Project::create(['name' => 'B', 'company_id' => $this->beta->id]);
        });
    }

    private function lead(Project $project, string $name, ?User $owner = null, array $extra = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create($extra + [
            'full_name' => $name, 'phone' => '05'.random_int(10000000, 99999999), 'phone_normalized' => '+9665'.random_int(10000000, 99999999),
            'email' => strtolower(str()->random(6)).'@x.test', 'project_id' => $project->id, 'assigned_to' => ($owner ?? $this->t['admin'])->id,
        ]));
    }

    private function visit(Lead $lead, User $owner): Appointment
    {
        return $this->asTenant($this->t['tenant'], fn () => Appointment::create([
            'lead_id' => $lead->id, 'project_id' => $lead->project_id, 'assigned_to' => $owner->id,
            'scheduled_at' => now()->addDay(), 'duration_minutes' => 30, 'status' => 'confirmed',
        ]));
    }

    private function memberOf(User $user, Company $company): User
    {
        $user->companies()->attach($company->id);

        return $user->fresh();
    }

    private function allow(User $user, string $permission): void
    {
        $this->asTenant($this->t['tenant'], fn () => PermissionRule::create(['layer' => 'user', 'user_id' => $user->id, 'permission' => $permission, 'effect' => 'allow']));
    }

    // ---------------------------------------------------------------- H1

    public function test_h1_the_calendar_feed_keeps_the_company_limits_the_screen_has(): void
    {
        $manager = $this->memberOf($this->makeUser($this->t['tenant'], Role::Manager), $this->alpha);
        $this->visit($this->lead($this->alphaProject, 'Alpha Visitor', $manager), $manager);
        $this->visit($this->lead($this->betaProject, 'Beta Visitor', $manager), $manager);

        $url = parse_url($this->actingAs($manager)->postJson('/api/v1/auth/calendar-feed/rotate')->json('data.url'), PHP_URL_PATH);
        $this->flushHeaders();
        auth()->forgetGuards();

        $ics = $this->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('Alpha Visitor', $ics);
        $this->assertStringNotContainsString('Beta Visitor', $ics, 'A one-company manager must not see the other company’s visits.');

        // An admin, who sees the whole workspace on screen, still gets both.
        $adminUrl = parse_url($this->actingAs($this->t['admin'])->postJson('/api/v1/auth/calendar-feed/rotate')->json('data.url'), PHP_URL_PATH);
        auth()->forgetGuards();
        $all = $this->get($adminUrl)->assertOk()->getContent();
        $this->assertStringContainsString('Alpha Visitor', $all);
        $this->assertStringContainsString('Beta Visitor', $all);
    }

    // ---------------------------------------------------------------- H2

    public function test_h2_erasure_reaches_merged_duplicates_and_what_automation_and_alerts_wrote(): void
    {
        $kept = $this->lead($this->alphaProject, 'Sara Kept');
        $dup = $this->lead($this->alphaProject, 'Sara Duplicate', null, ['email' => 'sara.dup@x.test']);
        $this->asTenant($this->t['tenant'], function () use ($kept, $dup) {
            $dup->forceFill(['merged_into_id' => $kept->id, 'merged_at' => now()])->saveQuietly();
            $dup->deleteQuietly();
            AutomationActionLog::create(['lead_id' => $kept->id, 'rule_name' => 'r', 'trigger' => 'lead.created', 'action_type' => 'add_note', 'result' => 'completed', 'description' => 'Wrote to Sara Kept', 'happened_at' => now()]);
        });
        DB::table('notifications')->insert(['id' => (string) str()->uuid(), 'tenant_id' => $this->t['tenant']->id, 'type' => 'x', 'notifiable_type' => User::class, 'notifiable_id' => $this->t['admin']->id,
            'data' => json_encode(['kind' => 'hot_lead', 'title' => 'Sara Kept is hot', 'body' => 'Call Sara', 'lead_id' => $dup->uuid]), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$kept->uuid}/erase", ['confirm' => 'Sara Kept'])->assertOk();

        $row = DB::table('leads')->where('id', $dup->id)->first();
        $this->assertNull($row->email, 'The merged duplicate kept the email.');
        $this->assertNull($row->phone_normalized);
        $this->assertNotSame('Sara Duplicate', $row->full_name);
        $this->assertNotNull($row->erased_at);
        $this->assertNull(DB::table('automation_action_logs')->where('lead_id', $kept->id)->value('description'));
        $this->assertStringNotContainsString('Sara', (string) DB::table('notifications')->value('data'));
        // The duplicate's address is on the do-not-contact list too.
        $this->assertTrue(DB::table('suppressions')->where('value', 'sara.dup@x.test')->exists());
        $this->assertStringNotContainsString('Sara', json_encode(DB::table('leads')->whereIn('id', [$kept->id, $dup->id])->get()));
    }

    // ---------------------------------------------------------------- M3

    public function test_m3_the_company_archive_is_only_for_someone_who_sees_the_whole_company(): void
    {
        $this->lead($this->alphaProject, 'Alpha Lead');
        $this->lead($this->betaProject, 'Beta Lead');

        // Settings rights, but one company: refused.
        $limited = $this->memberOf($this->makeUser($this->t['tenant'], Role::SalesManager), $this->alpha);
        $this->allow($limited, 'settings.edit');
        $this->actingAs($limited)->postJson('/api/v1/exports', ['kind' => 'company', 'format' => 'zip'])->assertForbidden();

        // Settings rights, and held to some projects: refused.
        $scoped = $this->makeUser($this->t['tenant'], Role::ProjectManager);
        $this->allow($scoped, 'settings.edit');
        $this->actingAs($scoped)->postJson('/api/v1/exports', ['kind' => 'company', 'format' => 'zip'])->assertForbidden();

        // Settings rights and no company restriction: allowed, as before.
        $whole = $this->makeUser($this->t['tenant'], Role::SalesManager);
        $this->allow($whole, 'settings.edit');
        $this->actingAs($whole)->postJson('/api/v1/exports', ['kind' => 'company', 'format' => 'zip'])->assertCreated();
        $this->actingAs($this->t['admin'])->postJson('/api/v1/exports', ['kind' => 'company', 'format' => 'zip'])->assertCreated();
    }

    // ---------------------------------------------------------------- M4

    public function test_m4_settings_rights_do_not_open_a_lead_the_role_cannot_see(): void
    {
        $role = $this->asTenant($this->t['tenant'], fn () => AccessRole::create(['key' => 'sales_plus', 'name' => 'Sales plus', 'base_role' => 'sales', 'project_scoped' => false, 'external' => false, 'grants' => ['leads' => ['view', 'edit', 'execute'], 'contacts' => ['view'], 'settings' => ['view', 'edit']]]));
        $rep = $this->makeUser($this->t['tenant'], Role::Sales, ['access_role_id' => $role->id]);
        $this->allow($rep, 'settings.edit');

        $notMine = $this->lead($this->alphaProject, 'Not Mine');
        $mine = $this->lead($this->alphaProject, 'Mine', $rep);

        $this->actingAs($rep)->get("/api/v1/leads/{$notMine->uuid}/personal-data")->assertForbidden();
        $this->actingAs($rep)->postJson("/api/v1/leads/{$notMine->uuid}/erase", ['confirm' => 'Not Mine'])->assertForbidden();
        $this->assertSame('Not Mine', DB::table('leads')->where('id', $notMine->id)->value('full_name'));

        // The visit file of a lead they cannot open is refused the same way.
        $visit = $this->visit($notMine, $this->t['admin']);
        $this->actingAs($rep)->get("/api/v1/appointments/{$visit->uuid}/ics")->assertForbidden();

        // Their own lead still works.
        $this->actingAs($rep)->get("/api/v1/leads/{$mine->uuid}/personal-data")->assertOk();

        // A permission-table role that may work leads but has no settings rights cannot export or erase at all.
        $plain = $this->makeUser($this->t['tenant'], Role::SalesManager);
        $this->actingAs($plain)->get("/api/v1/leads/{$mine->uuid}/personal-data")->assertForbidden();
        $this->actingAs($plain)->postJson("/api/v1/leads/{$mine->uuid}/erase", ['confirm' => 'Mine'])->assertForbidden();
    }

    // ---------------------------------------------------------------- L5

    public function test_l5_unknown_tokens_share_the_address_limit_and_are_not_cached(): void
    {
        for ($i = 0; $i < ApiKey::DEFAULT_RATE; $i++) {
            $this->flushHeaders();
            $this->withHeader('Authorization', 'Bearer mht_'.str()->random(40))->getJson('/api/v1/public/leads')->assertUnauthorized();
        }

        $this->flushHeaders();
        $this->withHeader('Authorization', 'Bearer mht_'.str()->random(40))->getJson('/api/v1/public/leads')->assertStatus(429);
        // Nothing about the random tokens was cached.
        $this->assertSame(0, collect(Cache::getStore() instanceof ArrayStore ? (fn () => $this->storage)->call(Cache::getStore()) : [])->keys()->filter(fn ($k) => str_contains($k, 'api-key-rate:'))->count());

        // A real key keeps its own bucket at its own rate. (From another address: the app-wide limit already holds
        // the flooding address for every unauthenticated call, key or not.)
        $plain = $this->actingAs($this->t['admin'])->postJson('/api/v1/api-keys', ['name' => 'k', 'scopes' => ['leads:read'], 'rate_limit_per_minute' => 10])->json('data.plain');
        app('auth')->forgetGuards();
        for ($i = 0; $i < 10; $i++) {
            $this->flushHeaders();
            $this->withHeader('Authorization', 'Bearer '.$plain)->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->getJson('/api/v1/public/leads')->assertOk();
        }
        $this->assertSame(10, Cache::get('api-key-rate:'.hash('sha256', $plain)));
        $this->flushHeaders();
        $this->withHeader('Authorization', 'Bearer '.$plain)->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->getJson('/api/v1/public/leads')->assertStatus(429);
    }

    // ---------------------------------------------------------------- L6

    public function test_l6_exports_leave_out_what_the_role_is_kept_from(): void
    {
        $this->lead($this->alphaProject, 'Hidden Contact', null, ['email' => 'secret@x.test', 'phone_normalized' => '+966500000001']);
        $finance = $this->makeUser($this->t['tenant'], Role::SalesManager);
        $this->allow($finance, 'leads.export');
        $this->asTenant($this->t['tenant'], fn () => PermissionRule::create(['layer' => 'user', 'user_id' => $finance->id, 'permission' => 'contacts.view', 'effect' => 'deny']));

        // The instant CSV.
        $csv = $this->actingAs($finance)->get('/api/v1/leads/export')->streamedContent();
        $this->assertStringContainsString('Hidden Contact', $csv);
        $this->assertStringNotContainsString('secret@x.test', $csv);
        $this->assertStringNotContainsString('+966500000001', $csv);

        // The background file.
        $id = $this->actingAs($finance)->postJson('/api/v1/exports', ['kind' => 'leads', 'format' => 'csv'])->assertCreated()->json('data.export.id');
        $file = Storage::disk('local')->get(DataExport::withoutGlobalScopes()->where('uuid', $id)->value('path'));
        $this->assertStringContainsString('Hidden Contact', $file);
        $this->assertStringNotContainsString('secret@x.test', $file);

        // The admin's archive still carries the contact.
        $archive = $this->actingAs($this->t['admin'])->postJson('/api/v1/exports', ['kind' => 'company', 'format' => 'zip'])->json('data.export.id');
        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path(DataExport::withoutGlobalScopes()->where('uuid', $archive)->value('path')));
        $this->assertStringContainsString('secret@x.test', $zip->getFromName('leads.csv'));
    }
}
