<?php

namespace Tests\Feature;

use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The first-day question: does any of this actually work for us?
 *
 * Two things have to be true at once, and they pull against each other. The
 * test lead must travel the *real* pipeline, or it proves nothing. And it must
 * not appear in a single number that describes the business, or proving the
 * setup works moves the conversion rate.
 */
class TestLeadDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Onboarding Co');
    }

    private function diagnose(): array
    {
        return $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/onboarding/test-lead')
            ->assertOk()
            ->json('data');
    }

    public function test_it_reports_every_stage_of_the_pipeline(): void
    {
        $data = $this->diagnose();

        $this->assertTrue($data['ok']);

        $keys = collect($data['steps'])->pluck('key')->all();

        $this->assertSame([
            'ingestion', 'normalization', 'dedup', 'attribution',
            'scoring', 'assignment', 'automation', 'notification',
        ], $keys);
    }

    /** A green tick tells a manager nothing they can check. */
    public function test_each_stage_says_what_it_actually_did(): void
    {
        $steps = collect($this->diagnose()['steps'])->keyBy('key');

        $this->assertStringContainsString('+9665', $steps['normalization']['detail']);
        $this->assertStringContainsString('meta', $steps['attribution']['detail']);
        $this->assertMatchesRegularExpression('/\d+/', $steps['scoring']['detail']);
    }

    /** It goes through the real path, not a shortcut that proves the shortcut works. */
    public function test_the_lead_is_normalised_scored_and_assigned_like_any_other(): void
    {
        $this->makeUser($this->t['tenant']);

        $this->diagnose();

        $lead = $this->asTenant(
            $this->t['tenant'],
            fn () => Lead::withTestLeads()->where('is_test', true)->first(),
        );

        $this->assertNotNull($lead->phone_normalized);
        $this->assertGreaterThan(0, $lead->score);
        $this->assertNotNull($lead->assigned_to);
        $this->assertSame('meta', $lead->platform);
        $this->assertNotNull($lead->click_id, 'the click id must survive, or attribution proves nothing');
    }

    /**
     * The part that matters most. Proving the setup works must not move a
     * single number a workspace reports on.
     */
    public function test_a_test_lead_appears_in_no_business_number(): void
    {
        $this->diagnose();

        $dashboard = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/dashboard')->assertOk()->json('data');

        $this->assertSame(0, $dashboard['summary']['total_leads']);
        $this->assertSame(0, $dashboard['summary']['new']);

        $list = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/leads')->assertOk()->json('meta.total');

        $this->assertSame(0, $list);
    }

    /** And it does not show up as somebody's work to do, either. */
    public function test_a_test_lead_is_not_offered_to_a_rep(): void
    {
        $this->diagnose();

        $next = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/work/next')->assertOk()->json('data.lead');

        $this->assertNull($next);
    }

    /** Running it twice must not report a duplicate — the dedup step is under test. */
    public function test_running_it_twice_produces_two_independent_leads(): void
    {
        $first = $this->diagnose();
        $second = $this->diagnose();

        $this->assertNotSame($first['lead']['id'], $second['lead']['id']);

        $dedup = collect($second['steps'])->firstWhere('key', 'dedup');
        $this->assertTrue($dedup['ok'], $dedup['detail']);
    }

    public function test_the_report_can_be_read_again_once_automation_catches_up(): void
    {
        $id = $this->diagnose()['lead']['id'];

        $this->actingAs($this->t['admin'])
            ->getJson("/api/v1/onboarding/test-lead/{$id}")
            ->assertOk()
            ->assertJsonPath('data.lead.id', $id);
    }

    /** A workspace should be able to prove the setup works and leave no trace. */
    public function test_the_test_lead_can_be_removed_completely(): void
    {
        $id = $this->diagnose()['lead']['id'];

        $this->actingAs($this->t['admin'])
            ->deleteJson("/api/v1/onboarding/test-lead/{$id}")
            ->assertOk();

        $this->asTenant($this->t['tenant'], function () {
            $this->assertSame(0, Lead::withTestLeads()->count());
        });
    }

    /** That endpoint must not become a way to hard-delete a real customer. */
    public function test_the_delete_endpoint_refuses_a_real_lead(): void
    {
        $real = $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => 'عميل حقيقي',
            'phone_normalized' => '+966500123123',
            'status' => 'new',
        ]));

        $this->actingAs($this->t['admin'])
            ->deleteJson("/api/v1/onboarding/test-lead/{$real->uuid}")
            ->assertNotFound();

        $this->asTenant($this->t['tenant'], fn () => $this->assertNotNull(Lead::find($real->id)));
    }

    public function test_a_sales_rep_cannot_run_it(): void
    {
        $this->actingAs($this->makeUser($this->t['tenant']))
            ->postJson('/api/v1/onboarding/test-lead')
            ->assertForbidden();
    }
}
