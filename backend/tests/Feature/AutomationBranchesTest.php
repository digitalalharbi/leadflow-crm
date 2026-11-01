<?php

namespace Tests\Feature;

use App\Domain\Automation\ConditionEvaluator;
use App\Models\AutomationRule;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A rule can bracket conditions — "(Riyadh or Jeddah) and score over 50" — and say what to do otherwise. A bracket
 * is one level deep; anything the evaluator would not read is refused when the rule is written.
 */
class AutomationBranchesTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Branch Co');
    }

    private function lead(array $attributes): Lead
    {
        return $this->asTenant($this->t['tenant'], function () use ($attributes) {
            $lead = Lead::create(['full_name' => 'L '.str()->random(4), 'phone' => '05'.random_int(10000000, 99999999)] + $attributes);
            $lead->forceFill(['score' => $attributes['score'] ?? 0])->save();

            return $lead->fresh();
        });
    }

    private function cityAndScore(string $match = 'all'): array
    {
        return [
            ['group' => 'any', 'conditions' => [
                ['field' => 'city', 'operator' => 'equals', 'value' => 'Riyadh'],
                ['field' => 'city', 'operator' => 'equals', 'value' => 'Jeddah'],
            ]],
            ['field' => 'score', 'operator' => 'greater_than', 'value' => 50],
        ];
    }

    public function test_a_bracket_is_read_with_its_own_all_or_any(): void
    {
        $evaluator = app(ConditionEvaluator::class);
        $conditions = $this->cityAndScore();

        $this->assertTrue($evaluator->evaluate($this->lead(['city' => 'Jeddah', 'score' => 70]), $conditions)['matched']);
        $this->assertFalse($evaluator->evaluate($this->lead(['city' => 'Dammam', 'score' => 70]), $conditions)['matched']);
        $this->assertFalse($evaluator->evaluate($this->lead(['city' => 'Riyadh', 'score' => 10]), $conditions)['matched']);

        // Under "any" at the top, the bracket alone is enough.
        $this->assertTrue($evaluator->evaluate($this->lead(['city' => 'Riyadh', 'score' => 10]), $conditions, [], 'any')['matched']);
    }

    public function test_a_bracket_inside_a_bracket_or_an_empty_one_stops_the_rule(): void
    {
        $evaluator = app(ConditionEvaluator::class);
        $lead = $this->lead(['city' => 'Riyadh']);

        $nested = [['group' => 'any', 'conditions' => [['group' => 'all', 'conditions' => [['field' => 'city', 'operator' => 'equals', 'value' => 'Riyadh']]]]]];
        $this->assertFalse($evaluator->evaluate($lead, $nested, [], 'any')['matched']);
        $this->assertFalse($evaluator->evaluate($lead, [['group' => 'any', 'conditions' => []]], [], 'any')['matched']);
    }

    public function test_the_api_saves_a_bracket_and_an_otherwise_branch_and_refuses_what_it_cannot_read(): void
    {
        $body = [
            'name' => 'فرعان', 'trigger' => 'lead.created', 'conditions' => $this->cityAndScore(),
            'actions' => [['type' => 'add_tag', 'tag' => 'مؤهل']],
            'else_actions' => [['type' => 'add_tag', 'tag' => 'متابعة']],
        ];

        $saved = $this->actingAs($this->t['admin'])->postJson('/api/v1/automation/rules', $body)->assertCreated()->json('data.rule');
        $this->assertSame('any', $saved['conditions'][0]['group']);
        $this->assertCount(2, $saved['conditions'][0]['conditions']);
        $this->assertSame([['type' => 'add_tag', 'tag' => 'متابعة']], $saved['else_actions']);

        $nested = $body;
        $nested['conditions'] = [['group' => 'any', 'conditions' => [['group' => 'all', 'conditions' => []]]]];
        $this->actingAs($this->t['admin'])->postJson('/api/v1/automation/rules', $nested)->assertUnprocessable();

        $unknown = $body;
        $unknown['conditions'] = [['group' => 'any', 'conditions' => [['field' => 'password', 'operator' => 'equals', 'value' => 'x']]]];
        $this->actingAs($this->t['admin'])->postJson('/api/v1/automation/rules', $unknown)->assertUnprocessable();

        $badElse = $body;
        $badElse['else_actions'] = [['type' => 'add_tag']];
        $this->actingAs($this->t['admin'])->postJson('/api/v1/automation/rules', $badElse)->assertUnprocessable();

        // Extra keys on a condition do not survive the trip.
        $extra = $body;
        $extra['conditions'] = [['field' => 'city', 'operator' => 'equals', 'value' => 'Riyadh', 'sql' => 'drop']];
        $kept = $this->actingAs($this->t['admin'])->postJson('/api/v1/automation/rules', $extra)->assertCreated()->json('data.rule.conditions.0');
        $this->assertSame(['field' => 'city', 'operator' => 'equals', 'value' => 'Riyadh'], $kept);
    }

    public function test_the_otherwise_branch_runs_when_the_conditions_do_not_hold(): void
    {
        $this->asTenant($this->t['tenant'], fn () => AutomationRule::create([
            'name' => 'فرعان', 'trigger' => 'lead.created', 'is_enabled' => true,
            'conditions' => [['field' => 'city', 'operator' => 'equals', 'value' => 'Riyadh']],
            'actions' => [['type' => 'add_tag', 'tag' => 'الرياض']],
            'else_actions' => [['type' => 'add_tag', 'tag' => 'خارج الرياض']],
        ]));

        $this->actingAs($this->t['admin'])->postJson('/api/v1/leads', ['full_name' => 'In', 'phone' => '0551110001', 'city' => 'Riyadh'])->assertSuccessful();
        $this->actingAs($this->t['admin'])->postJson('/api/v1/leads', ['full_name' => 'Out', 'phone' => '0551110002', 'city' => 'Dammam'])->assertSuccessful();

        $tags = $this->asTenant($this->t['tenant'], fn () => Lead::pluck('tags', 'full_name')->all());
        $this->assertSame(['الرياض'], $tags['In']);
        $this->assertSame(['خارج الرياض'], $tags['Out']);
    }
}
