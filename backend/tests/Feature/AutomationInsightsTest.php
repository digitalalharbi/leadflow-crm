<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Whether the rules are doing anything.
 *
 * The complaint that matters is not that a rule did something wrong — it is
 * that a rule did nothing at all, quietly, for a month. These tests are mostly
 * about making that visible rather than making the good news look good.
 */
class AutomationInsightsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private AutomationRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Insights Co');

        $this->rule = $this->asTenant($this->t['tenant'], fn () => AutomationRule::create([
            'name' => 'قاعدة الاختبار',
            'trigger' => 'lead.created',
            'conditions' => [],
            'actions' => [['type' => 'add_note', 'body' => 'x']],
        ]));
    }

    private function record(string $outcome, int $daysAgo = 0, array $actions = []): AutomationRun
    {
        return $this->asTenant($this->t['tenant'], function () use ($outcome, $daysAgo, $actions) {
            $lead = Lead::create([
                'full_name' => 'عميل',
                'phone_normalized' => '+9665008'.self::uniqueDigits(5),
                'status' => 'new',
            ]);

            $run = AutomationRun::create([
                'lead_id' => $lead->id,
                'automation_rule_id' => $this->rule->id,
                'trigger' => 'lead.created',
                'outcome' => $outcome,
                'applied_actions' => $actions,
            ]);

            $run->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();

            return $run->fresh();
        });
    }

    private function insights(): array
    {
        return $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/automation/insights?days=30')
            ->assertOk()
            ->json('data');
    }

    public function test_it_returns_a_point_for_every_day(): void
    {
        $data = $this->insights();

        $this->assertCount(30, $data['daily']);
        $this->assertSame(now()->toDateString(), end($data['daily'])['date']);
    }

    /**
     * Skips are plotted, not hidden. Two hundred skipped runs and no
     * applications is a workspace whose conditions stopped matching its leads,
     * and that looks exactly like a quiet day if you only draw what applied.
     */
    public function test_skipped_runs_are_reported_not_swallowed(): void
    {
        $this->record('skipped');
        $this->record('skipped');
        $this->record('applied', 0, [['type' => 'add_note', 'description' => 'x']]);

        $today = collect($this->insights()['daily'])->firstWhere('date', now()->toDateString());

        $this->assertSame(2, $today['skipped']);
        $this->assertSame(1, $today['applied']);
    }

    public function test_failures_are_their_own_number(): void
    {
        $this->record('failed');

        $today = collect($this->insights()['daily'])->firstWhere('date', now()->toDateString());

        $this->assertSame(1, $today['failed']);
    }

    /**
     * The headline counts actions carried out, not runs. A run that considered
     * a rule and skipped it saved nobody anything, and counting it would let
     * the number grow while the product did less.
     */
    public function test_the_headline_counts_work_done_not_rules_considered(): void
    {
        $this->record('applied', 0, [
            ['type' => 'assign', 'description' => 'a'],
            ['type' => 'add_note', 'description' => 'b'],
        ]);
        $this->record('skipped', 0, []);

        $summary = $this->insights()['summary'];

        $this->assertSame(2, $summary['runs']);
        $this->assertSame(1, $summary['applied']);
        $this->assertSame(2, $summary['actions'], 'two actions were carried out, in one run');
    }

    /** A rule that never applies is the row somebody needs to see. */
    public function test_a_rule_that_only_ever_skips_still_appears(): void
    {
        $this->record('skipped');
        $this->record('skipped');

        $rows = $this->insights()['by_rule'];

        $this->assertCount(1, $rows);
        $this->assertSame('قاعدة الاختبار', $rows[0]['rule']);
        $this->assertSame(0, $rows[0]['applied']);
        $this->assertSame(2, $rows[0]['skipped']);
    }

    public function test_runs_outside_the_window_are_excluded(): void
    {
        $this->record('applied', 60);

        $this->assertSame(0, $this->insights()['summary']['runs']);
    }

    public function test_it_never_counts_another_workspace(): void
    {
        $other = $this->provisionTenant('Elsewhere Co');

        $this->asTenant($other['tenant'], function () {
            $rule = AutomationRule::create([
                'name' => 'سر', 'trigger' => 'lead.created',
                'conditions' => [], 'actions' => [['type' => 'add_note', 'body' => 'x']],
            ]);
            $lead = Lead::create([
                'full_name' => 'سر', 'phone_normalized' => '+966500777666', 'status' => 'new',
            ]);
            AutomationRun::create([
                'lead_id' => $lead->id, 'automation_rule_id' => $rule->id,
                'trigger' => 'lead.created', 'outcome' => 'applied', 'applied_actions' => [],
            ]);
        });

        $this->assertSame(0, $this->insights()['summary']['runs']);
        $this->assertSame([], $this->insights()['by_rule']);
    }

    /** The person holding the phone asks this question too. */
    public function test_a_sales_rep_can_read_it(): void
    {
        $this->actingAs($this->makeUser($this->t['tenant']))
            ->getJson('/api/v1/automation/insights')
            ->assertOk();
    }
}
