<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AutomationRule;
use App\Models\Integration;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How far a workspace is from running on this.
 *
 * The rule the tests exist to defend: every item is answered by looking at the
 * data, never by a flag somebody could tick. A checklist that can be satisfied
 * by pressing Next is a checklist that lies on the day it matters.
 */
class SetupChecklistTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Checklist Co');
        $this->withoutJourney($this->t['tenant']);
    }

    private function steps(): array
    {
        return collect(
            $this->actingAs($this->t['admin'])
                ->getJson('/api/v1/onboarding/checklist')->assertOk()->json('data.steps')
        )->keyBy('key')->all();
    }

    public function test_it_reports_every_setup_step(): void
    {
        $this->assertSame([
            'identity', 'projects', 'team', 'sources',
            'assignment', 'sla', 'automation', 'test_lead',
        ], array_keys($this->steps()));
    }

    /** Each item points at the screen that fixes it, not at a dead end. */
    public function test_each_unfinished_step_says_where_to_go(): void
    {
        foreach ($this->steps() as $key => $step) {
            $this->assertNotEmpty($step['where'], "{$key} has nowhere to send anybody");
            $this->assertStringStartsWith('/', $step['where']);
        }
    }

    public function test_a_project_satisfies_the_project_step(): void
    {
        $this->assertFalse($this->steps()['projects']['done']);

        $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'مشروع']));

        $this->assertTrue($this->steps()['projects']['done']);
    }

    /**
     * An admin is not somebody to give leads to. A workspace whose only user
     * is the owner can be configured perfectly and still have nobody to call
     * anyone — and round-robin over an empty list assigns nothing.
     */
    public function test_an_admin_alone_does_not_satisfy_the_team_step(): void
    {
        $this->assertFalse($this->steps()['team']['done']);

        $this->makeUser($this->t['tenant'], Role::Sales);

        $this->assertTrue($this->steps()['team']['done']);
    }

    /** A deactivated rep is not a team either. */
    public function test_an_inactive_rep_does_not_satisfy_the_team_step(): void
    {
        $this->makeUser($this->t['tenant'], Role::Sales, ['is_active' => false]);

        $this->assertFalse($this->steps()['team']['done']);
    }

    /**
     * Every workspace is provisioned with a working generic webhook, so
     * "is an integration connected" answers itself for everybody. The step has
     * to ask whether leads are actually arriving.
     */
    public function test_the_default_webhook_alone_does_not_satisfy_the_sources_step(): void
    {
        $this->assertFalse(
            $this->steps()['sources']['done'],
            'a webhook URL nobody has ever posted to is not a source',
        );
    }

    public function test_an_unconnected_platform_does_not_satisfy_the_sources_step(): void
    {
        $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'meta',
            'name' => 'Meta',
            'status' => Integration::CONFIGURATION_REQUIRED,
            'is_active' => true,
        ]));

        $this->assertFalse($this->steps()['sources']['done']);
    }

    public function test_connecting_a_platform_satisfies_the_sources_step(): void
    {
        $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'meta',
            'name' => 'Meta',
            'status' => Integration::CONNECTED,
            'is_active' => true,
        ]));

        $this->assertTrue($this->steps()['sources']['done']);
    }

    /** And so does the default webhook, once something has actually arrived. */
    public function test_leads_arriving_through_the_webhook_satisfy_it_too(): void
    {
        $this->asTenant(
            $this->t['tenant'],
            fn () => Integration::query()->update(['leads_received' => 3]),
        );

        $this->assertTrue($this->steps()['sources']['done']);
    }

    /** A rule that exists but is switched off does nothing. */
    public function test_a_disabled_rule_does_not_satisfy_the_automation_step(): void
    {
        $this->asTenant($this->t['tenant'], fn () => AutomationRule::create([
            'name' => 'قاعدة متوقفة',
            'trigger' => 'lead.created',
            'is_enabled' => false,
            'conditions' => [],
            'actions' => [['type' => 'add_note', 'body' => 'x']],
        ]));

        $this->assertFalse($this->steps()['automation']['done']);

        $this->asTenant($this->t['tenant'], fn () => AutomationRule::query()->update(['is_enabled' => true]));

        $this->assertTrue($this->steps()['automation']['done']);
    }

    /** Running the diagnostic is what ticks its own box. */
    public function test_the_test_lead_step_is_satisfied_by_running_it(): void
    {
        $this->assertFalse($this->steps()['test_lead']['done']);

        $this->actingAs($this->t['admin'])->postJson('/api/v1/onboarding/test-lead')->assertOk();

        $this->assertTrue($this->steps()['test_lead']['done']);
    }

    public function test_a_finished_workspace_reports_complete(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            Project::create(['name' => 'مشروع']);
            Integration::create([
                'provider' => 'meta', 'name' => 'Meta',
                'status' => Integration::CONNECTED, 'is_active' => true,
            ]);
            AutomationRule::create([
                'name' => 'قاعدة', 'trigger' => 'lead.created', 'is_enabled' => true,
                'conditions' => [], 'actions' => [['type' => 'add_note', 'body' => 'x']],
            ]);
        });

        $this->makeUser($this->t['tenant'], Role::Sales);
        $this->t['tenant']->branding->forceFill(['company_name' => 'شركة'])->save();
        $this->t['tenant']->settings->forceFill([
            'assignment_mode' => 'round_robin',
            'first_contact_target_minutes' => 30,
        ])->save();

        $this->actingAs($this->t['admin'])->postJson('/api/v1/onboarding/test-lead')->assertOk();

        $body = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/onboarding/checklist')->assertOk()->json('data');

        $this->assertTrue($body['complete'], json_encode($body['steps']));
        $this->assertSame($body['total'], $body['done']);
    }

    /** A manager asking why leads are not assigned should not need an admin. */
    public function test_a_manager_can_read_the_checklist(): void
    {
        $this->actingAs($this->makeUser($this->t['tenant'], Role::Manager))
            ->getJson('/api/v1/onboarding/checklist')
            ->assertOk();
    }

    public function test_a_sales_rep_cannot(): void
    {
        $this->actingAs($this->makeUser($this->t['tenant']))
            ->getJson('/api/v1/onboarding/checklist')
            ->assertForbidden();
    }

    /** One workspace's progress is not another's. */
    public function test_the_checklist_does_not_cross_tenants(): void
    {
        $other = $this->provisionTenant('Elsewhere Co');
        $this->asTenant($other['tenant'], fn () => Project::create(['name' => 'سر']));

        $this->assertFalse($this->steps()['projects']['done']);
    }
}
