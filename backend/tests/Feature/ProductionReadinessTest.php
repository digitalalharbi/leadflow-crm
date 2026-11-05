<?php

namespace Tests\Feature;

use App\Domain\Integrations\IntegrationState;
use App\Domain\Readiness\ProductionReadiness;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Role;
use App\Models\CommissioningRun;
use App\Models\EmailDelivery;
use App\Models\Integration;
use App\Models\LandingForm;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Is it live" is a claim, and each of these says what it takes to make it.
 *
 * The property under test is not that the screen shows green things. It is that
 * the third state cannot be reached by typing: credentials make a row ready,
 * a successful test keeps it ready, and only something the other end did makes
 * it live.
 */
class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Ready Co');

        Cache::flush();
    }

    private function row(string $key, ?string $url = null): array
    {
        $response = $this->actingAs($this->t['admin'])->getJson($url ?? '/api/v1/readiness')->assertOk();

        return collect($response->json('data.items'))->firstWhere('key', $key);
    }

    private function whatsappCredentials(): Integration
    {
        return $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'whatsapp',
            'name' => 'WhatsApp Business',
            'status' => Integration::CONNECTED,
            'config' => ['phone_number_id' => '1555000999'],
            'credentials' => ['access_token' => 'EAAB', 'verify_token' => 'v', 'app_secret' => 's'],
            'is_active' => true,
        ]));
    }

    // -------------------------------------------------------- the baseline

    public function test_a_fresh_workspace_is_waiting_on_everything_and_says_what_for(): void
    {
        $items = collect(
            $this->actingAs($this->t['admin'])->getJson('/api/v1/readiness')->assertOk()->json('data.items'),
        );

        $this->assertSame(
            ['vps', 'domain', 'google', 'mail', 'google_login', 'automation', 'landing', 'reports', 'whatsapp', 'twilio', 'ads', 'email', 'claude', 'calendar'],
            $items->pluck('key')->all(),
        );

        // The calendar feed needs nothing from outside: ready from the start, waiting only for a calendar to read it.
        $calendar = $items->firstWhere('key', 'calendar');
        $this->assertSame(ProductionReadiness::READY, $calendar['status']);
        $this->assertSame('awaiting_first_event', $calendar['blocker']);

        // The engine needs nothing from outside either: it only reports its own failed jobs.
        $this->assertSame(ProductionReadiness::READY, $items->firstWhere('key', 'automation')['status']);

        foreach ($items->whereNotIn('key', ['calendar', 'automation']) as $item) {
            $this->assertSame(ProductionReadiness::EXTERNAL, $item['status'], "{$item['key']} claimed more than it had");
            $this->assertNotNull($item['blocker'], "{$item['key']} is waiting and does not say for what");
        }
    }

    // ------------------------------------------------- ready is not verified

    public function test_credentials_make_a_row_ready_and_never_live(): void
    {
        $this->whatsappCredentials();

        $row = $this->row('whatsapp');

        $this->assertSame(ProductionReadiness::READY, $row['status']);
        $this->assertSame('awaiting_first_event', $row['blocker']);
    }

    /**
     * The line the whole screen exists for.
     *
     * A successful test proves the credentials were accepted *today*. It does
     * not prove a customer was ever reached, and a green that arrived on the
     * strength of a test is the green that is wrong on the day it matters.
     */
    public function test_a_successful_commissioning_does_not_make_anything_live(): void
    {
        $this->whatsappCredentials();

        Http::fake(['graph.facebook.com/*' => Http::response([
            'display_phone_number' => '+966 55 123 4567',
            'verified_name' => 'Ready Co',
        ])]);

        $result = $this->actingAs($this->t['admin'])->postJson('/api/v1/readiness/whatsapp/commission')
            ->assertOk()
            ->assertJsonPath('data.result.ok', true);

        $row = collect($result->json('data.readiness.items'))->firstWhere('key', 'whatsapp');

        $this->assertSame(ProductionReadiness::READY, $row['status']);
        $this->assertTrue($row['last_commissioning']['ok']);
    }

    public function test_a_delivery_receipt_from_a_live_workspace_is_what_makes_it_live(): void
    {
        $this->whatsappCredentials();

        $lead = $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => 'سلمان', 'phone_normalized' => '+966551234567',
        ]));

        $this->asTenant($this->t['tenant'], fn () => LeadMessage::create([
            'lead_id' => $lead->id,
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::Whatsapp,
            'provider' => 'whatsapp_cloud',
            'provider_message_id' => 'wamid.REAL',
            'message_type' => 'text',
            'body' => 'x',
            'status' => MessageStatus::Delivered,
        ]));

        // From a developer machine: the provider is real and so is the receipt,
        // and neither says anything about the deployment customers use.
        $row = $this->row('whatsapp');
        $this->assertSame(ProductionReadiness::READY, $row['status']);
        $this->assertSame('not_recorded_in_production', $row['blocker']);
        $this->assertSame('ready', $row['state']);

        $this->asProductionDeployment();

        $row = $this->row('whatsapp');
        $this->assertSame(ProductionReadiness::LIVE, $row['status']);
        $this->assertNull($row['blocker']);
        $this->assertSame('production_verified', $row['state']);
    }

    public function test_every_row_has_a_rung_of_the_ladder_and_the_evidence_it_stands_on(): void
    {
        $report = $this->actingAs($this->t['admin'])->getJson('/api/v1/readiness')->assertOk()->json('data');

        $this->assertSame('local', $report['deployment']['kind']);

        foreach ($report['items'] as $item) {
            $this->assertContains($item['state'], IntegrationState::LADDER, $item['key']);
            $this->assertNotEmpty($item['evidence'] ?? [], "{$item['key']} claims a state with no evidence");

            // Nothing on a developer machine is entitled to a rung above ready.
            $this->assertNotContains($item['state'], ['live_verified', 'production_verified'], $item['key']);
        }

        foreach ($report['infrastructure'] as $row) {
            $this->assertContains($row['ladder'], IntegrationState::LADDER, $row['key']);
            $this->assertNotEmpty($row['evidence'], $row['key']);
            $this->assertNotContains($row['ladder'], ['live_verified', 'production_verified'], $row['key']);
        }

        $this->assertSame(array_sum($report['ladder']), count($report['items']) + count($report['infrastructure']));
    }

    public function test_the_evidence_says_what_was_seen_and_where(): void
    {
        $this->whatsappCredentials();
        $this->asTenant($this->t['tenant'], fn () => CommissioningRun::create(['key' => 'whatsapp', 'ok' => true, 'steps' => []]));

        $evidence = collect($this->row('whatsapp')['evidence'])->keyBy('key');

        $this->assertTrue($evidence['credentials_entered']['ok']);
        $this->assertTrue($evidence['accepted_by_provider']['ok']);
        $this->assertNotNull($evidence['accepted_by_provider']['at']);
        $this->assertFalse($evidence['real_event']['ok']);
        $this->assertFalse($evidence['recorded_in_production']['ok']);
        $this->assertSame('local', $evidence['recorded_in_production']['detail']);
    }

    public function test_production_means_the_deployment_can_be_reached_not_the_environment_name(): void
    {
        $this->app['env'] = 'production';
        config(['app.url' => 'http://localhost:8000']);

        $this->assertSame('local', $this->actingAs($this->t['admin'])->getJson('/api/v1/readiness')->json('data.deployment.kind'));

        $this->asProductionDeployment();

        $this->assertSame('production', $this->actingAs($this->t['admin'])->getJson('/api/v1/readiness')->json('data.deployment.kind'));
    }

    /** Having a credential Meta refuses is not "ready", whatever the form says. */
    public function test_a_credential_the_provider_rejected_is_not_reported_ready(): void
    {
        $this->whatsappCredentials();

        Http::fake(['graph.facebook.com/*' => Http::response(
            ['error' => ['message' => 'Invalid OAuth access token.']], 401,
        )]);

        $this->actingAs($this->t['admin'])->postJson('/api/v1/readiness/whatsapp/commission')
            ->assertOk()
            ->assertJsonPath('data.result.ok', false);

        $row = $this->row('whatsapp');

        $this->assertSame(ProductionReadiness::EXTERNAL, $row['status']);
        $this->assertSame('commissioning_failed', $row['blocker']);
        // And it says what Meta said, which is the part somebody acts on.
        $this->assertStringContainsString('Invalid OAuth', json_encode($row['last_commissioning']['steps']));
    }

    // ----------------------------------------------------------------- google

    public function test_google_is_ready_once_the_client_and_the_picker_are_configured(): void
    {
        $this->assertSame(ProductionReadiness::EXTERNAL, $this->row('google')['status']);

        config([
            'integrations.google_sheets.client_id' => 'id.apps.googleusercontent.com',
            'integrations.google_sheets.client_secret' => 'secret',
        ]);

        // A client without a picker is not ready: somebody can connect an
        // account and then has no way to choose a file.
        $row = $this->row('google');
        $this->assertSame(ProductionReadiness::EXTERNAL, $row['status']);
        $this->assertSame('picker_not_configured', $row['blocker']);

        config([
            'integrations.google_sheets.picker_api_key' => 'AIza',
            'integrations.google_sheets.picker_app_id' => '123456789012',
        ]);

        $row = $this->row('google');
        $this->assertSame(ProductionReadiness::READY, $row['status']);
        $this->assertSame('awaiting_consent', $row['blocker']);
    }

    public function test_the_google_client_is_checked_without_anybody_consenting(): void
    {
        config([
            'integrations.google_sheets.client_id' => 'id.apps.googleusercontent.com',
            'integrations.google_sheets.client_secret' => 'wrong',
            'integrations.google_sheets.picker_api_key' => 'AIza',
            'integrations.google_sheets.picker_app_id' => '123456789012',
        ]);

        // One stub, answered from a variable. `Http::fake()` adds to the stubs
        // already registered and the first match wins, so faking a second time
        // would leave this test asserting against the first answer.
        $answer = ['error' => 'invalid_client'];

        // By reference: an arrow function would capture the value as it is now.
        Http::fake(['oauth2.googleapis.com/token' => function () use (&$answer) {
            return Http::response($answer, 400);
        }]);

        $result = $this->actingAs($this->t['admin'])->postJson('/api/v1/readiness/google/commission')
            ->assertOk();

        $result->assertJsonPath('data.result.ok', false);
        $result->assertJsonPath('data.result.steps.0.detail', 'client_rejected');

        // And the opposite: a correct client is refused the *code*, not the client.
        $answer = ['error' => 'invalid_grant'];

        $this->actingAs($this->t['admin'])->postJson('/api/v1/readiness/google/commission')
            ->assertOk()
            ->assertJsonPath('data.result.ok', true)
            // The account step is skipped, not passed: nobody has consented.
            ->assertJsonPath('data.result.steps.2.ok', null);
    }

    // -------------------------------------------------------- vps and domain

    public function test_the_server_is_live_only_when_something_has_been_running(): void
    {
        $this->asProductionDeployment();
        config(['app.debug' => false]);

        $this->assertSame(ProductionReadiness::READY, $this->row('vps')['status']);
        $this->assertSame('awaiting_scheduler', $this->row('vps')['blocker']);

        Artisan::call('leadflow:heartbeat');

        $this->assertSame(ProductionReadiness::LIVE, $this->row('vps')['status']);

        // And a pulse that stopped is no pulse.
        Cache::put('platform:scheduler_heartbeat', time() - 600);

        $row = $this->row('vps');
        $this->assertSame(ProductionReadiness::READY, $row['status']);
    }

    public function test_a_server_that_runs_as_production_at_a_local_address_is_not_production(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => false, 'app.url' => 'http://localhost:8000']);
        Artisan::call('leadflow:heartbeat');

        $row = $this->row('vps');

        $this->assertNotSame(ProductionReadiness::LIVE, $row['status']);
        $this->assertNotContains($row['state'], ['live_verified', 'production_verified']);
    }

    public function test_debug_mode_in_production_is_never_ready(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true]);

        $row = $this->row('vps');

        $this->assertSame(ProductionReadiness::EXTERNAL, $row['status']);
        $this->assertSame('debug_enabled', $row['blocker']);
    }

    public function test_the_domain_is_live_when_this_request_arrived_securely_on_it(): void
    {
        config(['app.url' => 'https://crm.mahtam-clients.sa']);

        // Configured, but reached some other way — by IP, by an old name.
        $this->assertSame(
            ProductionReadiness::READY,
            $this->row('domain', 'https://other-host.example.net/api/v1/readiness')['status'],
        );

        // Reached securely on its own name — and a staging or test run of the same
        // name is not the production deployment.
        $row = $this->row('domain', 'https://crm.mahtam-clients.sa/api/v1/readiness');
        $this->assertSame(ProductionReadiness::READY, $row['status']);

        $this->app['env'] = 'production';
        $row = $this->row('domain', 'https://crm.mahtam-clients.sa/api/v1/readiness');

        $this->assertSame(ProductionReadiness::LIVE, $row['status']);
        $this->assertSame('production_verified', $row['state']);
    }

    public function test_a_local_address_is_not_a_domain(): void
    {
        foreach (['http://localhost:8000', 'https://127.0.0.1', 'https://app.example.com', 'https://crm.test'] as $url) {
            config(['app.url' => $url]);

            $row = $this->row('domain');

            $this->assertSame(ProductionReadiness::EXTERNAL, $row['status'], "{$url} was accepted as a domain");
        }
    }

    // ------------------------------------------------------------------ scope

    public function test_the_platform_owner_sees_the_platform_rows_and_not_a_customers(): void
    {
        $owner = User::create([
            'tenant_id' => null,
            'name' => 'Owner',
            'email' => 'owner@platform.test',
            'password' => 'password123',
            'role' => Role::SuperAdmin,
        ]);

        $items = collect(
            $this->actingAs($owner)->getJson('/api/v1/super-admin/readiness')->assertOk()->json('data.items'),
        )->keyBy('key');

        $this->assertSame('platform', $this->actingAs($owner)->getJson('/api/v1/super-admin/readiness')->json('data.scope'));

        foreach (['whatsapp', 'twilio', 'ads'] as $key) {
            $this->assertSame('configured_per_workspace', $items[$key]['blocker']);
            $this->assertFalse($items[$key]['commissionable']);
        }
    }

    public function test_a_rep_cannot_read_or_run_it(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->actingAs($rep)->getJson('/api/v1/readiness')->assertForbidden();
        $this->actingAs($rep)->postJson('/api/v1/readiness/whatsapp/commission')->assertForbidden();
    }

    public function test_an_unknown_integration_is_not_commissioned(): void
    {
        $this->actingAs($this->t['admin'])->postJson('/api/v1/readiness/mystery/commission')->assertNotFound();

        $this->assertSame(0, CommissioningRun::withoutGlobalScopes()->count());
    }

    /** One workspace's test result is not another's. */
    public function test_commissioning_results_do_not_cross_workspaces(): void
    {
        $this->whatsappCredentials();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'No']], 400)]);

        $this->actingAs($this->t['admin'])->postJson('/api/v1/readiness/whatsapp/commission')->assertOk();

        $other = $this->provisionTenant('Other Co');

        $response = $this->actingAs($other['admin'])->getJson('/api/v1/readiness')->assertOk();
        $row = collect($response->json('data.items'))->firstWhere('key', 'whatsapp');

        $this->assertNull($row['last_commissioning']);
    }

    public function test_email_is_only_live_when_a_customer_actually_opened_one(): void
    {
        $row = fn () => collect($this->actingAs($this->t['admin'])->getJson('/api/v1/readiness')->json('data.items'))->firstWhere('key', 'email');

        // The log mailer: nothing reaches anybody.
        $this->assertSame(ProductionReadiness::EXTERNAL, $row()['status']);

        // The platform door is the product's own SMTP provider, configured: the one way email now leaves.
        config(['leadflow_mail.provider' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.mailers.smtp.username' => 'user']);
        $this->assertSame(ProductionReadiness::READY, $row()['status']);

        // An open on a message the log mailer "sent" proves nothing.
        $lead = $this->asTenant($this->t['tenant'], fn () => Lead::create(['full_name' => 'x', 'phone' => '+966550000009', 'phone_normalized' => '+966550000009']));
        $this->asTenant($this->t['tenant'], fn () => LeadMessage::create([
            'lead_id' => $lead->id, 'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Email, 'provider' => 'mock',
            'message_type' => 'text', 'subject' => 's', 'body' => 'b', 'status' => MessageStatus::Read, 'read_at' => now(),
        ]));
        $this->assertNotSame(ProductionReadiness::LIVE, $row()['status']);

        // Through a real transport, opened by somebody's mail client — on a developer machine.
        $this->asTenant($this->t['tenant'], fn () => LeadMessage::withoutGlobalScopes()->where('channel', 'email')->update(['provider' => 'smtp']));
        $this->assertSame(ProductionReadiness::READY, $row()['status']);
        $this->assertSame('not_recorded_in_production', $row()['blocker']);

        // And only in production is it live.
        $this->asProductionDeployment();
        $this->assertSame(ProductionReadiness::LIVE, $row()['status']);
    }

    public function test_claude_is_ready_with_a_key_and_live_only_once_it_answered(): void
    {
        $row = fn () => collect($this->actingAs($this->t['admin'])->getJson('/api/v1/readiness')->json('data.items'))->firstWhere('key', 'claude');

        config(['services.anthropic.key' => null]);
        $this->assertSame(ProductionReadiness::EXTERNAL, $row()['status']);

        config(['services.anthropic.key' => 'sk-test']);
        $this->assertSame(ProductionReadiness::READY, $row()['status']);
    }

    public function test_landing_and_reports_are_only_ready_on_real_evidence(): void
    {
        $this->assertSame('awaiting_first_published_page', $this->row('landing')['blocker']);
        $this->assertSame('awaiting_first_report', $this->row('reports')['blocker']);

        $this->asTenant($this->t['tenant'], function () {
            $project = Project::create(['name' => 'Real']);
            $form = LandingForm::create(['name' => 'P', 'project_id' => $project->id, 'status' => 'published', 'fields' => [], 'consent_required' => false]);
            $this->assertSame('awaiting_first_submission', $this->row('landing')['blocker']);

            Lead::create(['full_name' => 'A person', 'phone' => '0551234567', 'project_id' => $project->id])->forceFill(['landing_form_id' => $form->id])->saveQuietly();
        });

        $this->assertNull($this->row('landing')['blocker']);
        $this->assertSame('ready', $this->row('landing')['status']);
    }

    public function test_the_readiness_command_shows_why_mail_failed_without_the_address(): void
    {
        $this->asTenant($this->t['tenant'], fn () => EmailDelivery::create([
            'kind' => 'test', 'to_email' => 'someone@customer.test', 'subject' => 'x', 'html' => 'x', 'provider' => 'smtp',
            'status' => EmailDelivery::FAILED, 'last_error' => '550 rejected for someone@customer.test', 'attempts' => 1, 'max_attempts' => 1,
        ]));

        Artisan::call('leadflow:readiness');
        $output = Artisan::output();

        $this->assertStringContainsString('email_failure 1x 550 rejected for [address]', $output);
        $this->assertStringContainsString('email_failed_recipient 1x reserved_test_domain not_an_account kind=test distinct=1', $output);
        $this->assertStringContainsString('evidence workspaces active=', $output);
        $this->assertStringNotContainsString('someone@customer.test', $output);
    }
}
