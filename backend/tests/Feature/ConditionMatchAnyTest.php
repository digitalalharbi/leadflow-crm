<?php

namespace Tests\Feature;

use App\Domain\Automation\AutomationEngine;
use App\Domain\Automation\ConditionEvaluator;
use App\Events\LeadCreated;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A rule that needs any one of its conditions rather than all of them.
 *
 * Conditions were a flat list combined with AND, which left a whole shape of
 * rule unwritable: "call back or no answer" is one follow-up sequence, and a
 * workspace wanting it had to write the rule twice and keep the two in step by
 * hand — which they do not, so the pair drifts and nobody notices until a
 * customer stops being chased.
 *
 * Still a flat list, deliberately. One choice for the whole list is the most a
 * builder can express honestly; a nested tree is a second rule wearing a
 * structure nobody can read back.
 */
class ConditionMatchAnyTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Match Co');
    }

    private function rule(array $conditions, ?string $match = null): AutomationRule
    {
        return $this->asTenant($this->t['tenant'], fn () => AutomationRule::create(array_filter([
            'name' => 'قاعدة',
            'trigger' => 'lead.created',
            'conditions' => $conditions,
            'condition_match' => $match,
            'actions' => [['type' => 'add_note', 'body' => 'طُبِّقت']],
            'is_enabled' => true,
        ])));
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل',
            'phone_normalized' => '+96650013'.random_int(1000, 9999),
            'status' => 'new',
            'score' => 10,
            'city' => 'الدمام',
        ], $attributes)));
    }

    private function fire(Lead $lead): void
    {
        $this->asTenant(
            $this->t['tenant'],
            fn () => app(AutomationEngine::class)->handle(new LeadCreated($lead)),
        );
    }

    private function applied(AutomationRule $rule): int
    {
        return AutomationRun::withoutGlobalScopes()
            ->where('automation_rule_id', $rule->id)
            ->where('outcome', 'applied')
            ->count();
    }

    private function skipReason(AutomationRule $rule): ?string
    {
        return AutomationRun::withoutGlobalScopes()
            ->where('automation_rule_id', $rule->id)
            ->latest('id')
            ->value('reason');
    }

    private array $either = [
        ['field' => 'city', 'operator' => 'equals', 'value' => 'الرياض'],
        ['field' => 'city', 'operator' => 'equals', 'value' => 'جدة'],
    ];

    public function test_any_matches_when_one_condition_holds(): void
    {
        $rule = $this->rule($this->either, ConditionEvaluator::MATCH_ANY);

        $this->fire($this->lead(['city' => 'جدة']));

        $this->assertSame(1, $this->applied($rule));
    }

    public function test_any_does_not_match_when_none_hold(): void
    {
        $rule = $this->rule($this->either, ConditionEvaluator::MATCH_ANY);

        $this->fire($this->lead(['city' => 'أبها']));

        $this->assertSame(0, $this->applied($rule));
    }

    /**
     * The same two conditions under the old behaviour can never both be true,
     * so this is the rule that was impossible to write — and the reason the
     * toggle exists.
     */
    public function test_the_same_conditions_under_all_can_never_match(): void
    {
        $rule = $this->rule($this->either, ConditionEvaluator::MATCH_ALL);

        $this->fire($this->lead(['city' => 'جدة']));

        $this->assertSame(0, $this->applied($rule));
    }

    /** Every rule written before this existed means exactly what it meant. */
    public function test_a_rule_that_says_nothing_still_needs_all_of_them(): void
    {
        $rule = $this->rule([
            ['field' => 'city', 'operator' => 'equals', 'value' => 'الدمام'],
            ['field' => 'score', 'operator' => 'greater_than', 'value' => 50],
        ]);

        $this->assertSame(ConditionEvaluator::MATCH_ALL, $rule->fresh()->condition_match);

        $this->fire($this->lead(['city' => 'الدمام', 'score' => 10]));

        $this->assertSame(0, $this->applied($rule));
    }

    /** A manager reading the trace has to be told what failed, not that it did. */
    public function test_the_trace_names_every_condition_that_failed(): void
    {
        $rule = $this->rule($this->either, ConditionEvaluator::MATCH_ANY);

        $this->fire($this->lead(['city' => 'أبها']));

        $reason = $this->skipReason($rule);

        $this->assertStringContainsString('None of the conditions matched', (string) $reason);
        $this->assertStringContainsString('الرياض', (string) $reason);
        $this->assertStringContainsString('جدة', (string) $reason);
    }

    /**
     * A condition nobody can read stops the rule in either mode. Skipping over
     * it under "any" would let a rule fire on the strength of the conditions
     * that happen to still parse.
     */
    public function test_a_malformed_condition_stops_the_rule_even_under_any(): void
    {
        $rule = $this->rule([
            ['field' => '', 'operator' => '', 'value' => null],
            ['field' => 'city', 'operator' => 'equals', 'value' => 'الدمام'],
        ], ConditionEvaluator::MATCH_ANY);

        $this->fire($this->lead(['city' => 'الدمام']));

        $this->assertSame(0, $this->applied($rule));
    }

    /** No conditions means every event of that kind, in either mode. */
    public function test_an_empty_list_still_matches_everything(): void
    {
        $rule = $this->rule([], ConditionEvaluator::MATCH_ANY);

        $this->fire($this->lead());

        $this->assertSame(1, $this->applied($rule));
    }

    public function test_the_api_accepts_and_returns_the_choice(): void
    {
        $uuid = $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/rules', [
                'name' => 'أي من الشرطين',
                'trigger' => 'lead.created',
                'conditions' => $this->either,
                'condition_match' => 'any',
                'actions' => [['type' => 'add_note', 'body' => 'x']],
            ])
            ->assertCreated()
            ->assertJsonPath('data.rule.condition_match', 'any')
            ->json('data.rule.id');

        $this->assertSame('any', AutomationRule::withoutGlobalScopes()
            ->where('uuid', $uuid)->value('condition_match'));
    }

    public function test_an_unknown_match_mode_is_refused(): void
    {
        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/rules', [
                'name' => 'قاعدة',
                'trigger' => 'lead.created',
                'conditions' => [],
                'condition_match' => 'maybe',
                'actions' => [['type' => 'add_note', 'body' => 'x']],
            ])
            ->assertStatus(422);
    }

    /** The preview must answer the question the rule will ask when it runs. */
    public function test_the_preview_counts_by_the_rules_own_mode(): void
    {
        $this->lead(['city' => 'جدة']);
        $this->lead(['city' => 'الرياض']);
        $this->lead(['city' => 'أبها']);

        $rule = $this->rule($this->either, ConditionEvaluator::MATCH_ANY);

        $this->assertSame(2, $this->actingAs($this->t['admin'])
            ->getJson("/api/v1/automation/rules/{$rule->uuid}/preview")
            ->assertOk()
            ->json('data.would_match'));
    }
}
