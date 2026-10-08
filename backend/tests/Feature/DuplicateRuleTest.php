<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AutomationRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A copy to edit, rather than a rule to retype.
 *
 * A workspace running three projects writes the same rule three times with one
 * condition different, and retyping it is where the second copy quietly stops
 * matching the first — a condition missed, a delay that is two days in one and
 * two hours in another. Nobody notices until a project stops being chased.
 */
class DuplicateRuleTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Copies Co');
    }

    private function rule(array $overrides = []): AutomationRule
    {
        return $this->asTenant($this->t['tenant'], fn () => AutomationRule::create(array_merge([
            'name' => 'متابعة مشروع النرجس',
            'trigger' => 'lead.created',
            'conditions' => [['field' => 'city', 'operator' => 'equals', 'value' => 'الرياض']],
            'condition_match' => 'any',
            'actions' => [['type' => 'add_note', 'body' => 'تابع', 'delay_minutes' => 2880]],
            'priority' => 40,
            'stops_processing' => true,
            'is_enabled' => true,
        ], $overrides)));
    }

    private function duplicate(AutomationRule $rule)
    {
        return $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/automation/rules/{$rule->uuid}/duplicate");
    }

    /** Everything that makes the rule what it is comes across. */
    public function test_the_copy_is_the_same_rule(): void
    {
        $original = $this->rule();

        $uuid = $this->duplicate($original)->assertCreated()->json('data.rule.id');

        $copy = AutomationRule::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();

        $this->assertSame($original->trigger, $copy->trigger);
        $this->assertEquals($original->conditions, $copy->conditions);
        $this->assertSame($original->condition_match, $copy->condition_match);
        $this->assertEquals($original->actions, $copy->actions);
        $this->assertSame($original->priority, $copy->priority);
        $this->assertSame($original->stops_processing, $copy->stops_processing);
    }

    /**
     * Switched off, like an installed template and for the same reason: it is a
     * rule nobody has read yet, and it is about to be edited anyway.
     */
    public function test_the_copy_does_not_start_running(): void
    {
        $uuid = $this->duplicate($this->rule())->assertCreated()->json('data.rule.id');

        $this->assertFalse(
            AutomationRule::withoutGlobalScopes()->where('uuid', $uuid)->first()->is_enabled,
        );
    }

    public function test_the_copy_is_named_as_one(): void
    {
        $uuid = $this->duplicate($this->rule())->assertCreated()->json('data.rule.id');

        $this->assertSame(
            'نسخة من متابعة مشروع النرجس',
            AutomationRule::withoutGlobalScopes()->where('uuid', $uuid)->first()->name,
        );
    }

    /**
     * Three projects, three copies. Two rules with the same name in one list is
     * what makes the trace unreadable later.
     */
    public function test_duplicating_twice_does_not_produce_two_identical_names(): void
    {
        $rule = $this->rule();

        $first = $this->duplicate($rule)->assertCreated()->json('data.rule.name');
        $second = $this->duplicate($rule)->assertCreated()->json('data.rule.name');

        $this->assertNotSame($first, $second);
        $this->assertSame('نسخة من متابعة مشروع النرجس 2', $second);
    }

    /** The original is untouched, including whether it is running. */
    public function test_the_original_keeps_running(): void
    {
        $original = $this->rule();

        $this->duplicate($original)->assertCreated();

        $this->assertTrue($original->fresh()->is_enabled);
        $this->assertSame('متابعة مشروع النرجس', $original->fresh()->name);
    }

    /** A name at the column's limit must not produce one past it. */
    public function test_a_very_long_name_is_still_a_valid_one(): void
    {
        $rule = $this->rule(['name' => str_repeat('ط', 120)]);

        $uuid = $this->duplicate($rule)->assertCreated()->json('data.rule.id');
        $name = AutomationRule::withoutGlobalScopes()->where('uuid', $uuid)->first()->name;

        $this->assertLessThanOrEqual(120, mb_strlen($name));
    }

    public function test_a_sales_rep_cannot_duplicate_a_rule(): void
    {
        $rep = User::create([
            'tenant_id' => $this->t['tenant']->id,
            'name' => 'مندوب', 'email' => 'rep@copies.test',
            'password' => bcrypt('secret'), 'role' => Role::Sales->value, 'is_active' => true,
        ]);

        $this->actingAs($rep)
            ->postJson("/api/v1/automation/rules/{$this->rule()->uuid}/duplicate")
            ->assertForbidden();
    }

    public function test_a_rule_in_another_workspace_is_not_found(): void
    {
        $rule = $this->rule();
        $other = $this->provisionTenant('Somebody Else');

        $this->actingAs($other['admin'])
            ->postJson("/api/v1/automation/rules/{$rule->uuid}/duplicate")
            ->assertNotFound();

        $this->assertSame(1, AutomationRule::withoutGlobalScopes()->count());
    }
}
