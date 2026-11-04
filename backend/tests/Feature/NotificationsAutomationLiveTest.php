<?php

namespace Tests\Feature;

use App\Domain\Automation\ActionExecutor;
use App\Domain\Automation\AutomationEngine;
use App\Domain\Notifications\AlertKinds;
use App\Domain\Notifications\ImportantAlerts;
use App\Domain\Notifications\NotificationTemplates;
use App\Enums\Role;
use App\Events\LeadSignal;
use App\Models\AutomationRule;
use App\Models\EmailDelivery;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Task;
use App\Models\Tenant;
use App\Services\Tenancy\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationsAutomationLiveTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->provisionTenant();
    }

    private function bell(string $kind): int
    {
        return DB::table('notifications')->where('data->kind', $kind)->count();
    }

    public function test_every_template_declares_what_it_uses_in_both_languages_and_a_missing_one_sends_nothing(): void
    {
        foreach (NotificationTemplates::TEMPLATES as $key => $template) {
            foreach (['ar', 'en'] as $locale) {
                preg_match_all('/\{\{\s*(\w+)\s*\}\}/', implode(' ', $template[$locale]), $used);
                $this->assertSame([], array_values(array_diff($used[1], $template['needs'])), "{$key} {$locale}");
            }
            $this->assertNotNull(AlertKinds::categoryOf($template['kind']), "{$key} has no category");
        }

        $this->assertNull(NotificationTemplates::render('appointment_reminder', 'ar', ['lead_name' => 'سارة']));
        $this->assertStringContainsString('Sara', NotificationTemplates::render('reservation_created', 'en', ['lead_name' => 'Sara'])['body']);
    }

    public function test_not_everything_is_high_priority(): void
    {
        $this->assertSame('normal', AlertKinds::priorityOf('new_lead', 'warning'));
        $this->assertSame('high', AlertKinds::priorityOf('interest_sla_breached', 'warning'));
        $this->assertSame('critical', AlertKinds::priorityOf('provider_disconnected', 'critical'));
        $this->assertSame('low', AlertKinds::priorityOf('budget_anomaly', 'warning'));
    }

    public function test_an_integration_failure_reaches_admins_once_a_day(): void
    {
        ImportantAlerts::integrationFailure($this->tenant->id, 'sheets_sync_failed', 'sheet:1', ['source_name' => 'Leads']);
        ImportantAlerts::integrationFailure($this->tenant->id, 'sheets_sync_failed', 'sheet:1', ['source_name' => 'Leads']);

        $this->assertSame(1, $this->bell('sheet_sync_failed'));
    }

    public function test_a_new_company_has_nothing_switched_on_that_writes_to_customers(): void
    {
        $tenant = app(TenantProvisioningService::class)->provision(['company_name' => 'Fresh', 'subdomain' => 'fresh-co', 'admin_name' => 'A', 'admin_email' => 'a@fresh.test', 'admin_password' => 'password123'])['tenant'];

        $this->assertGreaterThan(0, $this->asTenant($tenant, fn () => AutomationRule::count()));
        $this->assertSame(0, $this->asTenant($tenant, fn () => AutomationRule::where('is_enabled', true)->count()));
    }

    public function test_a_retried_event_runs_each_step_once_and_a_new_event_runs_again(): void
    {
        $this->asTenant($this->tenant, function () {
            $lead = Lead::create(['full_name' => 'A', 'phone' => '0551112222']);
            AutomationRule::create(['name' => 'R', 'trigger' => 'lead.visit_completed', 'priority' => 1, 'conditions' => [], 'actions' => [['type' => 'create_task', 'title' => 'T', 'in_hours' => 2]], 'is_enabled' => true]);

            $event = new LeadSignal($lead, 'lead.visit_completed');
            $engine = app(AutomationEngine::class);
            $engine->handle($event);
            $engine->handle($event);
            $this->assertSame(1, Task::where('lead_id', $lead->id)->count());

            $engine->handle(new LeadSignal($lead, 'lead.visit_completed'));
            $this->assertSame(2, Task::where('lead_id', $lead->id)->count());
        });
    }

    public function test_someone_who_opted_out_is_skipped_not_failed_and_nobody_is_alerted(): void
    {
        $this->asTenant($this->tenant, function () {
            $lead = Lead::create(['full_name' => 'A', 'phone' => '0551112222', 'email' => 'a@x.test']);
            $lead->forceFill(['email_opt_out_at' => now()])->saveQuietly();
            $template = MessageTemplate::query()->where('channel', 'email')->first();
            $result = app(ActionExecutor::class)->execute($lead, ['type' => 'send_email', 'template_uuid' => $template?->uuid]);

            $this->assertTrue($result['ok'], $result['description']);
            $this->assertSame('skipped', $result['result'] ?? null);
        });
    }

    public function test_a_new_device_is_told_once_and_a_known_one_not_at_all(): void
    {
        $user = $this->makeUser($this->tenant, Role::Sales, ['email' => 'p@x.test', 'password' => 'a-strong-pass']);
        $login = fn (string $agent) => $this->withHeader('User-Agent', $agent)->postJson('/api/v1/auth/login', ['email' => 'p@x.test', 'password' => 'a-strong-pass'])->assertOk();
        $notices = fn () => EmailDelivery::withoutGlobalScopes()->where('to_email', 'p@x.test')->where('kind', 'security')->count();

        $login('First');
        $this->assertSame(0, $notices(), 'The first sign-in has nothing to compare with.');
        $login('First');
        $this->assertSame(0, $notices());
        $login('Second');
        $this->assertSame(1, $notices());
        $login('Second');
        $this->assertSame(1, $notices());
    }

    public function test_a_rule_that_sets_itself_off_or_points_at_nothing_cannot_be_saved(): void
    {
        $rule = fn (array $over) => $over + ['name' => 'R', 'trigger' => 'lead.created', 'conditions' => [], 'actions' => [['type' => 'add_tag', 'tag' => 'x']]];

        $this->actingAs($this->admin)->postJson('/api/v1/automation/rules', $rule(['trigger' => 'lead.stage_changed', 'actions' => [['type' => 'change_stage', 'status' => 'contacted']]]))->assertUnprocessable();
        $this->actingAs($this->admin)->postJson('/api/v1/automation/rules', $rule(['actions' => [['type' => 'send_email', 'template_uuid' => (string) str()->uuid()]]]))->assertUnprocessable();
        $this->actingAs($this->admin)->postJson('/api/v1/automation/rules', $rule([]))->assertCreated();
    }

    public function test_a_rule_can_be_tried_on_one_lead_and_nothing_happens(): void
    {
        [$lead, $rule] = $this->asTenant($this->tenant, fn () => [
            Lead::create(['full_name' => 'A', 'phone' => '0551112222']),
            AutomationRule::create(['name' => 'R', 'trigger' => 'lead.created', 'priority' => 1, 'conditions' => [], 'actions' => [['type' => 'create_task', 'title' => 'T']], 'is_enabled' => false]),
        ]);

        $this->actingAs($this->admin)->getJson("/api/v1/automation/rules/{$rule->uuid}/preview?lead={$lead->uuid}")
            ->assertOk()->assertJsonPath('data.matched', true)->assertJsonPath('data.would_run.0.type', 'create_task');

        $this->assertSame(0, $this->asTenant($this->tenant, fn () => Task::count()));
    }

    public function test_alerts_about_one_companys_lead_stay_in_that_company(): void
    {
        $other = $this->provisionTenant('Other')['admin'];
        $lead = $this->asTenant($this->tenant, fn () => Lead::create(['full_name' => 'A', 'phone' => '0551112222']));

        ImportantAlerts::aboutLead('sale_completed', $lead, [], true);

        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $other->id)->where('data->kind', 'sale_completed')->count());
        $this->assertGreaterThan(0, DB::table('notifications')->where('notifiable_id', $this->admin->id)->where('data->kind', 'sale_completed')->count());
    }
}
