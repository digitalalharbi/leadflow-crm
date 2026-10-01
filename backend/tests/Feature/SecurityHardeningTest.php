<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Lead;
use App\Models\LeadSourceEvent;
use App\Models\WebhookEndpoint;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Regressions for the issues found in the pre-release security review. */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_email_can_be_reused_after_the_user_is_removed(): void
    {
        // The unique index used to ignore soft deletes, so an admin could never
        // re-add someone they had removed — it failed with a 500.
        $t = $this->provisionTenant('Rehire Co');

        $created = $this->actingAs($t['admin'])->postJson('/api/v1/users', [
            'name' => 'Rep One', 'email' => 'rep@rehire.test',
            'password' => 'password123', 'role' => 'sales',
        ])->assertCreated()->json('data.user.id');

        $this->actingAs($t['admin'])->deleteJson("/api/v1/users/{$created}")->assertOk();

        $this->actingAs($t['admin'])->postJson('/api/v1/users', [
            'name' => 'Rep One Again', 'email' => 'rep@rehire.test',
            'password' => 'password123', 'role' => 'sales',
        ])->assertCreated();
    }

    public function test_the_email_is_still_unique_among_active_users(): void
    {
        $t = $this->provisionTenant('Unique Co');

        $this->actingAs($t['admin'])->postJson('/api/v1/users', [
            'name' => 'A', 'email' => 'dup@unique.test', 'password' => 'password123', 'role' => 'sales',
        ])->assertCreated();

        $this->actingAs($t['admin'])->postJson('/api/v1/users', [
            'name' => 'B', 'email' => 'dup@unique.test', 'password' => 'password123', 'role' => 'sales',
        ])->assertStatus(422);
    }

    public function test_a_sales_rep_cannot_read_another_users_import(): void
    {
        // Failed-row output contains the raw CSV, including leads the rep is not
        // assigned to — it must not be readable across the team.
        Storage::fake('local');
        $t = $this->provisionTenant('Imports Co');
        $rep = $this->makeUser($t['tenant'], Role::Sales);

        $file = UploadedFile::fake()->createWithContent('leads.csv', "name,mobile\nAdmin Upload,0512345678\n");

        $importId = $this->actingAs($t['admin'])
            ->post('/api/v1/imports', ['file' => $file])
            ->json('data.import.id');

        $this->actingAs($rep)->getJson("/api/v1/imports/{$importId}")->assertForbidden();

        $this->actingAs($rep)->postJson("/api/v1/imports/{$importId}/process", [
            'mapping' => ['name' => 'full_name'],
        ])->assertForbidden();
    }

    public function test_an_import_list_only_shows_a_reps_own_uploads(): void
    {
        Storage::fake('local');
        $t = $this->provisionTenant('Imports List Co');
        $rep = $this->makeUser($t['tenant'], Role::Sales);

        $this->actingAs($t['admin'])->post('/api/v1/imports', [
            'file' => UploadedFile::fake()->createWithContent('a.csv', "name\nX\n"),
        ])->assertCreated();

        $this->assertCount(0, $this->actingAs($rep)->getJson('/api/v1/imports')->json('data.imports'));
        $this->assertCount(1, $this->actingAs($t['admin'])->getJson('/api/v1/imports')->json('data.imports'));
    }

    public function test_a_suspended_tenant_cannot_keep_reading_notifications(): void
    {
        // Notification payloads carry lead names and phone numbers.
        $t = $this->provisionTenant('Suspended Co');

        $this->actingAs($t['admin'])->getJson('/api/v1/notifications')->assertOk();

        $t['tenant']->update(['is_active' => false]);

        $this->actingAs($t['admin'])->getJson('/api/v1/notifications')->assertStatus(403);

        // Signing out must still work, so the SPA is not stuck.
        $this->actingAs($t['admin'])->postJson('/api/v1/auth/logout')->assertOk();
    }

    public function test_a_malformed_notification_id_is_a_404_not_a_500(): void
    {
        $t = $this->provisionTenant('Notif Co');

        $this->actingAs($t['admin'])
            ->postJson('/api/v1/notifications/not-a-uuid/read')
            ->assertNotFound();
    }

    public function test_the_score_does_not_ratchet_when_a_status_is_toggled(): void
    {
        $t = $this->provisionTenant('Scoring Co');

        $lead = $this->asTenant($t['tenant'], fn () => Lead::create([
            'full_name' => 'Toggle Me',
            'phone' => '0512345678',
            'phone_normalized' => '+966512345678',
            'score' => 30,
        ]));

        $scores = [];

        foreach (['qualified', 'contacted', 'qualified', 'contacted', 'qualified'] as $status) {
            $this->actingAs($t['admin'])
                ->postJson("/api/v1/leads/{$lead->uuid}/status", ['status' => $status])
                ->assertOk();

            $scores[] = $lead->fresh()->score;
        }

        // The same stage must always produce the same score, however many times
        // a rep moves the lead back and forth.
        $this->assertSame($scores[0], $scores[2]);
        $this->assertSame($scores[2], $scores[4]);
        $this->assertLessThan(100, max($scores));
    }

    public function test_the_platform_owner_is_never_seeded_with_a_default_password(): void
    {
        config(['tenancy.super_admin.password' => null]);
        $this->app['env'] = 'production';

        $this->expectException(\RuntimeException::class);

        (new DatabaseSeeder)->run();
    }

    public function test_each_tenant_account_on_a_shared_email_can_sign_in(): void
    {
        // Emails are unique per tenant, so one consultant may admin several
        // agencies. Taking the first matching row used to lock the others out.
        $first = $this->provisionTenant('First Agency', [
            'admin_email' => 'shared@consultant.test',
            'admin_password' => 'first-password-1',
        ]);

        $second = $this->provisionTenant('Second Agency', [
            'admin_email' => 'shared@consultant.test',
            'admin_password' => 'second-password-2',
        ]);

        // Each password reaches its own account, with no tenant hint supplied.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'shared@consultant.test', 'password' => 'first-password-1',
        ])->assertOk()->assertJsonPath('data.branding.slug', $first['tenant']->slug);

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'shared@consultant.test', 'password' => 'second-password-2',
        ])->assertOk()->assertJsonPath('data.branding.slug', $second['tenant']->slug);
    }

    public function test_a_deactivated_account_does_not_shadow_an_active_one(): void
    {
        $dead = $this->provisionTenant('Closed Agency', [
            'admin_email' => 'shared2@consultant.test',
            'admin_password' => 'same-password-99',
        ]);

        $live = $this->provisionTenant('Live Agency', [
            'admin_email' => 'shared2@consultant.test',
            'admin_password' => 'same-password-99',
        ]);

        $dead['admin']->update(['is_active' => false]);

        // Same password on both; the usable account must win.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'shared2@consultant.test', 'password' => 'same-password-99',
        ])->assertOk()->assertJsonPath('data.branding.slug', $live['tenant']->slug);
    }

    public function test_the_pipeline_board_does_not_scale_queries_with_columns(): void
    {
        $t = $this->provisionTenant('Pipeline Co');

        $this->asTenant($t['tenant'], function () {
            foreach (range(1, 30) as $i) {
                Lead::create([
                    'full_name' => "Lead {$i}",
                    'phone_normalized' => '+96650'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                    'status' => ['new', 'contacted', 'qualified', 'appointment', 'won', 'lost'][$i % 6],
                ]);
            }
        });

        DB::enableQueryLog();

        $this->actingAs($t['admin'])->getJson('/api/v1/pipeline')->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Previously this ran a count plus a fetch plus two eager loads for each
        // of the six columns. It must not grow with the number of stages.
        $this->assertLessThan(12, $count, "Pipeline issued {$count} queries.");
    }

    public function test_a_credential_in_a_webhook_body_is_never_persisted_in_clear(): void
    {
        $t = $this->provisionTenant('Redaction Co');

        $endpoint = $this->asTenant($t['tenant'], fn () => WebhookEndpoint::first());
        $endpoint->forceFill(['secret_hash' => Hash::make('redact-secret-0123456789')])
            ->saveQuietly();

        $this->withHeader('X-Webhook-Secret', 'redact-secret-0123456789')
            ->postJson("/api/v1/integrations/webhooks/{$endpoint->key}/leads", [
                'name' => 'Probe',
                'phone' => '0512345678',
                'api_key' => 'sk-live-should-never-be-stored',
                'meta' => ['access_token' => 'ya29.should-never-be-stored'],
            ])->assertOk();

        $lead = Lead::withoutGlobalScopes()->first();
        $event = LeadSourceEvent::withoutGlobalScopes()->first();

        foreach ([json_encode($lead->provider_payload), json_encode($event->payload)] as $stored) {
            $this->assertStringNotContainsString('sk-live-should-never-be-stored', $stored);
            $this->assertStringNotContainsString('ya29.should-never-be-stored', $stored);
            $this->assertStringContainsString('[redacted]', $stored);
        }
    }

    public function test_a_user_cannot_be_moved_to_another_tenant(): void
    {
        $a = $this->provisionTenant('Move From');
        $b = $this->provisionTenant('Move To');

        $this->expectException(\RuntimeException::class);

        $a['admin']->update(['tenant_id' => $b['tenant']->id]);
    }

    public function test_authenticated_endpoints_are_rate_limited(): void
    {
        // The `api` limiter was defined but never attached to the route group.
        $this->assertContains(
            'throttle:api',
            app(Kernel::class)->getMiddlewareGroups()['api'] ?? [],
        );
    }

    public function test_only_explicitly_trusted_proxies_are_honoured(): void
    {
        // Trusting '*' lets a caller forge X-Forwarded-For, which bypasses the
        // login rate limiter and poisons the audit trail.
        $proxies = (new TrustProxies)->proxies ?? null;

        $this->assertNotSame('*', $proxies);
    }
}
