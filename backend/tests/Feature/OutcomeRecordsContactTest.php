<?php

namespace Tests\Feature;

use App\Domain\Leads\OutcomeRecorder;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Working a lead counts as having touched it.
 *
 * `last_contact_at` is what the product means by "when did anybody last do
 * anything with this lead". The status endpoint sets it, the follow-up screen
 * sets it, WhatsApp sets it — and the work queue, where a rep spends the whole
 * day, did not. So a lead called every morning for a week read as untouched
 * since the day it arrived.
 *
 * Three things read that column and all three were wrong about the same leads:
 * the data-quality screen listing stale leads, the re-engagement filter, and
 * `minutes_since_last_contact`, which a rule uses to escalate a lead nobody has
 * called — escalating the ones being called hardest.
 */
class OutcomeRecordsContactTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Contact Co');
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل',
            'phone_normalized' => '+9665009'.self::uniqueDigits(5),
            'status' => 'new',
            'assigned_to' => $this->t['admin']->id,
        ], $attributes)));
    }

    private function record(Lead $lead, string $outcome): void
    {
        $this->asTenant(
            $this->t['tenant'],
            fn () => app(OutcomeRecorder::class)->record($lead, $outcome, $this->t['admin']),
        );
    }

    /** The one that was wrong: a call nobody answered is still a call made. */
    public function test_an_unanswered_call_counts_as_having_worked_the_lead(): void
    {
        $lead = $this->lead(['status' => 'contacted']);
        $lead->forceFill(['last_contact_at' => now()->subDays(9)])->saveQuietly();

        $this->record($lead, 'no_answer');

        $this->assertTrue($lead->fresh()->last_contact_at->isToday());
    }

    public function test_every_outcome_counts(): void
    {
        foreach (OutcomeRecorder::available() as $outcome) {
            $lead = $this->lead();
            $lead->forceFill(['last_contact_at' => now()->subDays(9)])->saveQuietly();

            $this->record($lead, $outcome);

            $this->assertTrue(
                $lead->fresh()->last_contact_at->isToday(),
                "recording '{$outcome}' must count as touching the lead",
            );
        }
    }

    /**
     * The screen that was lying. A lead worked this morning must not appear on
     * a list of leads nobody has touched in a week.
     */
    public function test_a_lead_worked_today_is_not_listed_as_stale(): void
    {
        $lead = $this->lead(['status' => 'contacted']);
        // Old enough to qualify: the check wants a lead that has been around a
        // fortnight as well as untouched for one.
        $lead->forceFill([
            'last_contact_at' => now()->subDays(30),
            'created_at' => now()->subDays(30),
        ])->saveQuietly();

        $this->assertSame(1, $this->staleCount(), 'the lead must be stale before it is worked');

        $this->record($lead, 'no_answer');

        $this->assertSame(0, $this->staleCount());
    }

    private function staleCount(): int
    {
        $checks = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/data-quality')
            ->assertOk()
            ->json('data.checks');

        $stale = collect($checks)->firstWhere('key', 'stale');

        $this->assertNotNull($stale, 'the stale check must exist for this test to mean anything');

        return $stale['count'];
    }
}
