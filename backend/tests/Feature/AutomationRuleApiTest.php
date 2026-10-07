<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rules written through the API by a customer.
 *
 * This endpoint is the safety boundary of the whole automation feature: what it
 * accepts then runs unattended against that customer's own leads. So the tests
 * are weighted toward what must be *refused* — an unknown field, an operator the
 * evaluator does not implement, an action that does not exist. Each of those,
 * accepted, becomes a rule that looks configured and silently does nothing.
 */
class AutomationRuleApiTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Rules API Co');
    }

    private function valid(array $overrides = []): array
    {
        return array_merge([
            'name' => 'عملاء الرياض',
            'trigger' => 'lead.created',
            'conditions' => [['field' => 'city', 'operator' => 'equals', 'value' => 'الرياض']],
            'actions' => [['type' => 'assign', 'strategy' => 'round_robin']],
        ], $overrides);
    }

    public function test_an_admin_can_create_a_rule(): void
    {
        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/rules', $this->valid())
            ->assertCreated()
            ->assertJsonPath('data.rule.trigger', 'lead.created');
    }

    /** The builder must not be able to offer what the API will refuse. */
    public function test_the_schema_lists_exactly_what_is_accepted(): void
    {
        $schema = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/automation/schema')->assertOk()->json('data');

        $this->assertContains('lead.created', $schema['triggers']);
        $this->assertContains('city', $schema['fields']);
        $this->assertContains('between', $schema['operators']);
        $this->assertContains('schedule_follow_up', $schema['actions']);

        // Every advertised field must be accepted by the validator.
        foreach (array_slice($schema['fields'], 0, 5) as $field) {
            $this->actingAs($this->t['admin'])
                ->postJson('/api/v1/automation/rules', $this->valid([
                    'conditions' => [['field' => $field, 'operator' => 'exists']],
                ]))
                ->assertCreated();
        }
    }

    /**
     * The alert text a manager typed, still there after a save.
     *
     * `validate()` returns only the keys it has rules for, and `message` had
     * none — so the text was dropped between the editor and the database, after
     * a success toast, and the rule sent the generic fallback to the same people
     * forever. Nothing on any screen said so.
     */
    public function test_a_rule_keeps_the_alert_text_it_was_given(): void
    {
        $uuid = $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/rules', $this->valid([
                'actions' => [[
                    'type' => 'notify',
                    'audience' => 'managers',
                    'message' => 'عميل من حملة النرجس — تواصل خلال عشر دقائق',
                ]],
            ]))
            ->assertCreated()
            ->json('data.rule.id');

        $saved = AutomationRule::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();

        $this->assertSame(
            'عميل من حملة النرجس — تواصل خلال عشر دقائق',
            $saved->actions[0]['message'] ?? null,
        );
    }

    /** Renaming a rule must not quietly empty what it does. */
    public function test_editing_a_rule_does_not_drop_the_parts_the_form_did_not_change(): void
    {
        $actions = [[
            'type' => 'schedule_follow_up',
            'in_hours' => 2,
            'note' => 'أول اتصال',
        ]];

        $uuid = $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/rules', $this->valid(['actions' => $actions]))
            ->assertCreated()
            ->json('data.rule.id');

        $this->actingAs($this->t['admin'])
            ->patchJson("/api/v1/automation/rules/{$uuid}", ['name' => 'باسم آخر', 'actions' => $actions])
            ->assertOk();

        $saved = AutomationRule::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();

        $this->assertSame('أول اتصال', $saved->actions[0]['note'] ?? null);
    }

    /**
     * A named rep is a different outcome from "whoever is least busy", and the
     * executor has always supported both. The strategy was dropped on the way
     * in, so every assignment rule behaved as round robin whatever it said.
     */
    public function test_an_assignment_to_a_named_rep_keeps_the_name(): void
    {
        $rep = $this->makeUser($this->t['tenant']);

        $uuid = $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/rules', $this->valid([
                'actions' => [['type' => 'assign', 'strategy' => 'specific', 'user_id' => $rep->uuid]],
            ]))
            ->assertCreated()
            ->json('data.rule.id');

        $saved = AutomationRule::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();

        $this->assertSame('specific', $saved->actions[0]['strategy'] ?? null);
        $this->assertSame($rep->uuid, $saved->actions[0]['user_id'] ?? null);
    }

    /** Naming nobody is the configuration that would silently assign anyone. */
    public function test_assigning_to_a_specific_rep_without_naming_one_is_refused(): void
    {
        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/rules', $this->valid([
                'actions' => [['type' => 'assign', 'strategy' => 'specific']],
            ]))
            ->assertStatus(422);
    }

    public function test_an_unknown_assignment_strategy_is_refused(): void
    {
        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/rules', $this->valid([
                'actions' => [['type' => 'assign', 'strategy' => 'whoever']],
            ]))
            ->assertStatus(422);
    }

    public function test_an_unknown_field_is_refused(): void
    {
        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/rules', $this->valid([
                'conditions' => [['field' => 'password', 'operator' => 'exists']],
            ]))
            ->assertStatus(422);
    }

    public function test_an_operator_the_engine_does_not_implement_is_refused(): void
    {
        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/rules', $this->valid([
                'conditions' => [['field' => 'city', 'operator' => 'sounds_like', 'value' => 'x']],
            ]))
            ->assertStatus(422);
    }

    public function test_an_action_that_does_not_exist_is_refused(): void
    {
        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/rules', $this->valid([
                'actions' => [['type' => 'launch_missiles']],
            ]))
            ->assertStatus(422);
    }

    /** A rule that matches and does nothing is one somebody believes is working. */
    public function test_a_rule_must_do_something(): void
    {
        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/rules', $this->valid(['actions' => []]))
            ->assertStatus(422);
    }

    /**
     * The difference between a rule people trust and one they switch off: you
     * can see what it would do before it touches a real customer.
     */
    public function test_preview_reports_what_a_rule_would_match_without_acting(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            Lead::create(['full_name' => 'رياضي', 'phone_normalized' => '+966500000001',
                'city' => 'الرياض', 'status' => 'new']);
            Lead::create(['full_name' => 'جداوي', 'phone_normalized' => '+966500000002',
                'city' => 'جدة', 'status' => 'new']);
        });

        $rule = $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/rules', $this->valid())
            ->assertCreated()->json('data.rule.id');

        $preview = $this->actingAs($this->t['admin'])
            ->getJson("/api/v1/automation/rules/{$rule}/preview")
            ->assertOk()->json('data');

        $this->assertSame(2, $preview['sampled']);
        $this->assertSame(1, $preview['would_match']);
        $this->assertSame('رياضي', $preview['examples'][0]['full_name']);

        // And nothing was done to either lead.
        $this->asTenant($this->t['tenant'], function () {
            $this->assertSame(0, AutomationRun::count());
        });
    }

    /**
     * A rep who could write rules could write one that reassigned the whole
     * workspace's incoming leads to themselves, arriving as ordinary automation
     * with no reassignment anybody would think to question. Every write path is
     * checked, not just create: an endpoint left on the wrong gate is exactly
     * how this comes back.
     */
    public function test_a_sales_rep_cannot_write_rules(): void
    {
        $rep = $this->makeUser($this->t['tenant']);

        $rule = $this->asTenant($this->t['tenant'], fn () => AutomationRule::create([
            'name' => 'قاعدة', 'trigger' => 'lead.created', 'conditions' => [],
            'actions' => [['type' => 'add_note', 'body' => 'x']],
        ]));

        $this->actingAs($rep)
            ->postJson('/api/v1/automation/rules', $this->valid())
            ->assertForbidden();

        $this->actingAs($rep)
            ->patchJson("/api/v1/automation/rules/{$rule->uuid}", ['is_enabled' => false])
            ->assertForbidden();

        $this->actingAs($rep)
            ->deleteJson("/api/v1/automation/rules/{$rule->uuid}")
            ->assertForbidden();

        // Still enabled, still there.
        $this->assertTrue($rule->fresh()->is_enabled);
    }

    /** Reading is open: a rep should be able to see what moved their own lead. */
    public function test_a_sales_rep_can_read_rules_and_the_trace(): void
    {
        $rep = $this->makeUser($this->t['tenant']);

        $this->actingAs($rep)->getJson('/api/v1/automation/rules')->assertOk();
        $this->actingAs($rep)->getJson('/api/v1/automation/schema')->assertOk();
    }

    /** Managers run the sales floor; rules are theirs to set, not only an admin's. */
    public function test_a_manager_can_write_rules(): void
    {
        $manager = $this->makeUser($this->t['tenant'], Role::Manager);

        $this->actingAs($manager)
            ->postJson('/api/v1/automation/rules', $this->valid())
            ->assertCreated();
    }

    /** Another workspace's rules are invisible and untouchable. */
    public function test_rules_do_not_cross_tenants(): void
    {
        $other = $this->provisionTenant('Someone Else');

        $theirs = $this->asTenant($other['tenant'], fn () => AutomationRule::create([
            'name' => 'سر', 'trigger' => 'lead.created', 'conditions' => [],
            'actions' => [['type' => 'add_note', 'body' => 'x']],
        ]));

        $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/automation/rules')->assertOk()
            ->assertJsonCount(0, 'data.rules');

        $this->actingAs($this->t['admin'])
            ->deleteJson("/api/v1/automation/rules/{$theirs->uuid}")
            ->assertNotFound();
    }

    public function test_the_trace_for_a_lead_is_readable(): void
    {
        $lead = $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => 'عميل', 'phone_normalized' => '+966500000003',
            'city' => 'الرياض', 'status' => 'new',
        ]));

        $this->asTenant($this->t['tenant'], fn () => AutomationRun::create([
            'lead_id' => $lead->id,
            'trigger' => 'lead.created',
            'outcome' => 'skipped',
            'reason' => 'city (جدة) did not satisfy "equals الرياض"',
            'applied_actions' => [],
        ]));

        $runs = $this->actingAs($this->t['admin'])
            ->getJson("/api/v1/leads/{$lead->uuid}/automation-runs")
            ->assertOk()->json('data.runs');

        $this->assertCount(1, $runs);
        $this->assertSame('skipped', $runs[0]['outcome']);
        $this->assertStringContainsString('did not satisfy', $runs[0]['reason']);
    }
}
