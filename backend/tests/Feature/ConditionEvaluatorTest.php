<?php

namespace Tests\Feature;

use App\Domain\Automation\ConditionEvaluator;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The evaluator decides whether a tenant's rule fires, so its failure modes
 * matter more than its happy path: a rule that fires when it should not sends a
 * customer the wrong message, and one that silently never fires looks like the
 * product is broken.
 */
class ConditionEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private ConditionEvaluator $evaluator;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new ConditionEvaluator;
        $t = $this->provisionTenant('Rules Co');

        $this->lead = $this->asTenant($t['tenant'], fn () => Lead::create([
            'full_name' => 'عميل القواعد',
            'phone_normalized' => '+966500000001',
            'city' => 'الرياض',
            'budget_min' => 500000,
            'status' => 'new',
            'platform' => 'meta',
            'score' => 65,
        ]));
    }

    private function fires(array $conditions): bool
    {
        return $this->evaluator->evaluate($this->lead, $conditions)['matched'];
    }

    public function test_an_empty_condition_list_matches_everything(): void
    {
        $this->assertTrue($this->fires([]));
    }

    public function test_conditions_combine_with_and(): void
    {
        $this->assertTrue($this->fires([
            ['field' => 'city', 'operator' => 'equals', 'value' => 'الرياض'],
            ['field' => 'score', 'operator' => 'greater_than', 'value' => 50],
        ]));

        $this->assertFalse($this->fires([
            ['field' => 'city', 'operator' => 'equals', 'value' => 'الرياض'],
            ['field' => 'score', 'operator' => 'greater_than', 'value' => 90],
        ]));
    }

    /**
     * The failure this guards: "score greater than 50" matching a lead with no
     * score, because null quietly becomes 0 somewhere. A rule written to find
     * the best leads would then message everyone.
     */
    public function test_a_numeric_comparison_against_a_missing_value_is_false(): void
    {
        $this->lead->forceFill(['budget_max' => null])->saveQuietly();

        $this->assertFalse($this->fires([
            ['field' => 'budget_max', 'operator' => 'greater_than', 'value' => 0],
        ]));
        $this->assertFalse($this->fires([
            ['field' => 'budget_max', 'operator' => 'less_than', 'value' => 999999],
        ]));
    }

    public function test_an_unknown_operator_never_fires(): void
    {
        $this->assertFalse($this->fires([
            ['field' => 'city', 'operator' => 'sounds_like', 'value' => 'الرياض'],
        ]));
    }

    public function test_an_unknown_field_reads_as_empty_rather_than_throwing(): void
    {
        $this->assertFalse($this->fires([
            ['field' => 'nonexistent', 'operator' => 'exists'],
        ]));
        $this->assertTrue($this->fires([
            ['field' => 'nonexistent', 'operator' => 'not_exists'],
        ]));
    }

    /**
     * A rule must not be able to name its way to a column it was never offered.
     * The allow-list is the boundary; this is the test that it holds.
     */
    public function test_a_rule_cannot_read_a_field_outside_the_allow_list(): void
    {
        foreach (['password', 'remember_token', 'tenant_id', 'id'] as $forbidden) {
            $this->assertNull(
                $this->evaluator->read($this->lead, $forbidden),
                "'{$forbidden}' is readable by a rule."
            );
        }
    }

    public function test_text_comparison_ignores_case(): void
    {
        $this->assertTrue($this->fires([
            ['field' => 'platform', 'operator' => 'equals', 'value' => 'META'],
        ]));
        $this->assertTrue($this->fires([
            ['field' => 'city', 'operator' => 'contains', 'value' => 'ريا'],
        ]));
    }

    public function test_in_and_between(): void
    {
        $this->assertTrue($this->fires([
            ['field' => 'platform', 'operator' => 'in', 'value' => ['meta', 'tiktok']],
        ]));
        $this->assertFalse($this->fires([
            ['field' => 'platform', 'operator' => 'in', 'value' => ['google', 'tiktok']],
        ]));
        $this->assertTrue($this->fires([
            ['field' => 'score', 'operator' => 'between', 'value' => [60, 70]],
        ]));
        $this->assertFalse($this->fires([
            ['field' => 'score', 'operator' => 'between', 'value' => [70, 80]],
        ]));
    }

    public function test_a_malformed_condition_does_not_fire(): void
    {
        $this->assertFalse($this->fires([['operator' => 'equals', 'value' => 'x']]));
        $this->assertFalse($this->fires([['field' => 'city']]));
    }

    public function test_the_reason_names_the_condition_that_failed(): void
    {
        $result = $this->evaluator->evaluate($this->lead, [
            ['field' => 'score', 'operator' => 'greater_than', 'value' => 90],
        ]);

        $this->assertFalse($result['matched']);
        $this->assertStringContainsString('score', $result['reason']);
        $this->assertStringContainsString('90', $result['reason']);
    }

    public function test_boolean_fields_compare_as_booleans(): void
    {
        $this->assertTrue($this->fires([
            ['field' => 'is_assigned', 'operator' => 'equals', 'value' => false],
        ]));
        $this->assertTrue($this->fires([
            ['field' => 'has_phone', 'operator' => 'equals', 'value' => true],
        ]));
    }
}
