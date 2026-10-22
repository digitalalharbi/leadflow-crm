<?php

namespace Tests\Feature;

use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Next Lead" — the one button a rep should need.
 *
 * Two things must hold or the feature is worse than the list it replaces: it
 * must hand over the genuinely most urgent lead, and it must say clearly when
 * there is nothing left rather than showing an empty screen that looks broken.
 */
class WorkQueueTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Queue Co');
        $this->t['tenant']->settings->forceFill(['first_contact_target_minutes' => 30])->save();
    }

    private function lead(array $attributes, int $ageMinutes = 1): Lead
    {
        return $this->asTenant($this->t['tenant'], function () use ($attributes, $ageMinutes) {
            $lead = Lead::create(array_merge([
                'full_name' => 'عميل '.random_int(100, 999),
                'phone_normalized' => '+9665000'.random_int(10000, 99999),
                'status' => 'new',
                'assigned_to' => $this->t['admin']->id,
            ], $attributes));

            $lead->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->saveQuietly();

            return $lead->fresh();
        });
    }

    /**
     * Skipping has to actually move on. Without the server honouring `skip`,
     * the screen re-serves the lead the rep just passed over and the button
     * looks broken while behaving exactly as written.
     */
    public function test_a_skipped_lead_is_not_offered_again(): void
    {
        $first = $this->lead(['score' => 90], ageMinutes: 300);
        $second = $this->lead(['score' => 10], ageMinutes: 200);

        $top = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/work/next')->assertOk()->json('data.lead.id');

        $next = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/work/next?skip[]='.$top)->assertOk()->json('data.lead.id');

        $this->assertNotSame($top, $next);
        $this->assertContains($next, [$first->uuid, $second->uuid]);

        // Skipping every candidate empties the queue rather than looping.
        $empty = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/work/next?skip[]='.$first->uuid.'&skip[]='.$second->uuid)
            ->assertOk()->json('data');

        $this->assertNull($empty['lead']);
    }

    /** A skip list is client-supplied, so it is filtered before it reaches SQL. */
    public function test_a_malformed_skip_list_is_ignored_rather_than_failing(): void
    {
        $lead = $this->lead(['score' => 50], ageMinutes: 300);

        $body = $this->actingAs($this->t['admin'])
            ->getJson("/api/v1/work/next?skip[]=' or 1=1 --&skip[]=12345")
            ->assertOk()->json('data');

        $this->assertSame($lead->uuid, $body['lead']['id']);
    }

    public function test_an_empty_queue_says_so_rather_than_erroring(): void
    {
        $body = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/work/next')->assertOk()->json('data');

        $this->assertNull($body['lead']);
        $this->assertSame(0, $body['remaining']);
    }

    public function test_it_hands_over_the_most_urgent_lead_not_the_newest(): void
    {
        // Calm: contacted, follow-up days away.
        $this->lead([
            'full_name' => 'هادئ',
            'status' => 'contacted',
            'first_contact_at' => now()->subHours(2),
            'next_follow_up_at' => now()->addDays(3),
        ], ageMinutes: 200);

        // Urgent: never contacted, well past the target. Created earlier, so
        // a naive "newest first" would miss it.
        $this->lead(['full_name' => 'عاجل'], ageMinutes: 90);

        $body = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/work/next')->assertOk()->json('data');

        $this->assertSame('عاجل', $body['lead']['full_name']);
        $this->assertSame('critical', $body['action']['urgency']);
    }

    /** A lead that needs nothing now must not be handed over as work. */
    public function test_leads_that_need_nothing_are_not_offered(): void
    {
        $this->lead([
            'status' => 'contacted',
            'first_contact_at' => now()->subHours(2),
            'next_follow_up_at' => now()->addDays(3),
        ], ageMinutes: 200);

        $body = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/work/next')->assertOk()->json('data');

        $this->assertNull($body['lead']);
    }

    public function test_the_answer_carries_the_reason(): void
    {
        $this->lead([], ageMinutes: 60);

        $body = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/work/next')->assertOk()->json('data');

        $this->assertNotEmpty($body['action']['reason']);
        $this->assertSame('call_now', $body['action']['action']);
    }

    /** A rep is handed their own work, never somebody else's. */
    public function test_a_rep_is_never_handed_another_reps_lead(): void
    {
        $other = $this->makeUser($this->t['tenant']);
        $mine = $this->makeUser($this->t['tenant']);

        $this->lead(['full_name' => 'لغيري', 'assigned_to' => $other->id], ageMinutes: 120);
        $this->lead(['full_name' => 'لي', 'assigned_to' => $mine->id], ageMinutes: 60);

        $body = $this->actingAs($mine)
            ->getJson('/api/v1/work/next')->assertOk()->json('data');

        $this->assertSame('لي', $body['lead']['full_name']);
    }

    public function test_the_summary_counts_what_the_rep_is_carrying(): void
    {
        $this->lead([], ageMinutes: 60);                                    // overdue first contact
        $this->lead(['temperature' => 'hot'], ageMinutes: 5);               // hot, still in target
        $this->lead([
            'status' => 'contacted',
            'first_contact_at' => now()->subHour(),
            'next_follow_up_at' => now()->subMinutes(30),
        ], ageMinutes: 100);                                                // overdue follow-up

        $body = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/work/summary')->assertOk()->json('data');

        $this->assertSame(3, $body['open']);
        $this->assertSame(2, $body['uncontacted']);
        $this->assertSame(1, $body['overdue_first_contact']);
        $this->assertSame(1, $body['overdue_follow_ups']);
        $this->assertSame(1, $body['hot']);
    }

    /** Closed leads are not work. */
    public function test_closed_leads_are_not_counted_or_offered(): void
    {
        $this->lead(['status' => 'won'], ageMinutes: 500);
        $this->lead(['status' => 'lost'], ageMinutes: 500);

        $summary = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/work/summary')->assertOk()->json('data');

        $this->assertSame(0, $summary['open']);
    }

    public function test_another_tenants_leads_are_never_offered(): void
    {
        $other = $this->provisionTenant('Someone Else');

        $this->asTenant($other['tenant'], fn () => Lead::create([
            'full_name' => 'سر', 'phone_normalized' => '+966500777888', 'status' => 'new',
        ]));

        $body = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/work/next')->assertOk()->json('data');

        $this->assertNull($body['lead']);
    }
}
