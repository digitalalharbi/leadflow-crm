<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\FollowUp;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FollowUpTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Followup Realty');
        $this->lead = $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => 'Follow Me',
            'phone' => '0512345678',
            'phone_normalized' => '+966512345678',
            'assigned_to' => $this->t['admin']->id,
        ]));
    }

    public function test_a_follow_up_can_be_created_and_stamps_the_lead(): void
    {
        $due = now()->addDay()->startOfHour();

        $this->actingAs($this->t['admin'])->postJson('/api/v1/follow-ups', [
            'lead_id' => $this->lead->uuid,
            'due_at' => $due->toIso8601String(),
            'note' => 'اتصال لتأكيد الموعد',
        ])->assertCreated();

        // The lead carries the nearest pending follow-up so the table can sort on it.
        $this->assertEquals($due->timestamp, $this->lead->fresh()->next_follow_up_at->timestamp);
        $this->assertDatabaseHas('lead_activities', ['lead_id' => $this->lead->id, 'type' => 'follow_up_created']);
    }

    public function test_the_lead_always_points_at_the_earliest_pending_follow_up(): void
    {
        $later = now()->addDays(5)->startOfHour();
        $sooner = now()->addDay()->startOfHour();

        foreach ([$later, $sooner] as $due) {
            $this->actingAs($this->t['admin'])->postJson('/api/v1/follow-ups', [
                'lead_id' => $this->lead->uuid,
                'due_at' => $due->toIso8601String(),
            ])->assertCreated();
        }

        $this->assertEquals($sooner->timestamp, $this->lead->fresh()->next_follow_up_at->timestamp);
    }

    public function test_buckets_separate_overdue_today_and_upcoming(): void
    {
        $this->makeFollowUp(now()->subDays(2));
        $this->makeFollowUp(now()->setTime(14, 0));
        $this->makeFollowUp(now()->addDays(3));

        $counts = $this->actingAs($this->t['admin'])->getJson('/api/v1/follow-ups/counts')->json('data');

        $this->assertSame(1, $counts['overdue']);
        $this->assertSame(1, $counts['today']);
        $this->assertSame(1, $counts['upcoming']);

        $this->assertSame(1, $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/follow-ups?bucket=overdue')->json('meta.total'));
    }

    public function test_the_three_buckets_are_disjoint_and_cover_everything(): void
    {
        // A follow-up due earlier today is overdue, not "today" — otherwise it is
        // counted twice and the figures stop matching the work.
        $this->makeFollowUp(now()->subHours(2));          // earlier today
        $this->makeFollowUp(now()->subDays(2));           // earlier this week
        $this->makeFollowUp(now()->addHours(2));          // later today
        $this->makeFollowUp(now()->addDays(3));           // upcoming

        $counts = $this->actingAs($this->t['admin'])->getJson('/api/v1/follow-ups/counts')->json('data');

        $this->assertSame(2, $counts['overdue']);
        $this->assertSame(1, $counts['today']);
        $this->assertSame(1, $counts['upcoming']);

        // Disjoint and exhaustive: every pending follow-up sits in exactly one.
        $this->assertSame(
            4,
            $counts['overdue'] + $counts['today'] + $counts['upcoming'],
        );
    }

    public function test_completing_a_follow_up_clears_the_lead_marker_and_records_contact(): void
    {
        $followUp = $this->makeFollowUp(now()->addDay());
        $this->lead->forceFill(['next_follow_up_at' => $followUp->due_at])->saveQuietly();

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/follow-ups/{$followUp->uuid}/complete", ['outcome' => 'تم الاتصال'])
            ->assertOk()
            ->assertJsonPath('data.follow_up.status', 'completed');

        $this->lead->refresh();
        $this->assertNull($this->lead->next_follow_up_at);
        $this->assertNotNull($this->lead->last_contact_at);

        $this->assertDatabaseHas('lead_activities', ['lead_id' => $this->lead->id, 'type' => 'follow_up_completed']);
    }

    public function test_a_sales_rep_only_sees_their_own_follow_ups(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->makeFollowUp(now()->addDay());                       // admin's
        $this->makeFollowUp(now()->addDay(), assignee: $rep->id);    // rep's

        $this->assertSame(1, $this->actingAs($rep)->getJson('/api/v1/follow-ups?bucket=all')->json('meta.total'));
        $this->assertSame(2, $this->actingAs($this->t['admin'])->getJson('/api/v1/follow-ups?bucket=all')->json('meta.total'));
    }

    public function test_a_follow_up_cannot_be_created_for_a_lead_the_rep_cannot_see(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $other = $this->makeUser($this->t['tenant'], Role::Sales);

        $otherLead = $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => 'Not Yours',
            'phone_normalized' => '+966599999999',
            'assigned_to' => $other->id,
        ]));

        $this->actingAs($rep)->postJson('/api/v1/follow-ups', [
            'lead_id' => $otherLead->uuid,
            'due_at' => now()->addDay()->toIso8601String(),
        ])->assertForbidden();
    }

    public function test_cancelling_a_follow_up_removes_it_from_the_queue(): void
    {
        $followUp = $this->makeFollowUp(now()->addDay());

        $this->actingAs($this->t['admin'])->deleteJson("/api/v1/follow-ups/{$followUp->uuid}")->assertOk();

        $this->assertSame('cancelled', $followUp->fresh()->status);
        $this->assertSame(0, $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/follow-ups?bucket=upcoming')->json('meta.total'));
    }

    private function makeFollowUp($dueAt, ?int $assignee = null): FollowUp
    {
        return $this->asTenant($this->t['tenant'], fn () => FollowUp::create([
            'lead_id' => $this->lead->id,
            'assigned_to' => $assignee ?? $this->t['admin']->id,
            'created_by' => $this->t['admin']->id,
            'due_at' => $dueAt,
        ]));
    }
}
