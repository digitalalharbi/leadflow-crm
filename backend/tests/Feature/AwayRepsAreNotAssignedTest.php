<?php

namespace Tests\Feature;

use App\Domain\Leads\WorkloadBalancer;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\LeadAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nobody is handed a lead while they are away.
 *
 * Assignment's only gate was `is_active`, which is an account state — the thing
 * you set when somebody leaves the company, not when they are on leave until
 * Sunday. So leads kept going to whoever was away, and the balancer's own
 * docblock already said why that matters: "assigning leads to someone who is
 * away is how a lead sits untouched for a day inside a system whose whole
 * promise is that it will not."
 *
 * The other half is the one that decides whether this is an improvement at all.
 * A lead that goes to nobody because the whole team is away belongs to nobody,
 * appears on nobody's queue, and is found by whoever next reads the
 * data-quality screen — which is worse than one waiting on a rep who is back on
 * Sunday.
 */
class AwayRepsAreNotAssignedTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Away Co');
        $this->t['tenant']->settings->forceFill(['assignment_strategy' => 'workload'])->save();
    }

    private function rep(?string $awayUntil = null): User
    {
        $rep = $this->makeUser($this->t['tenant']);

        if ($awayUntil !== null) {
            $rep->forceFill(['away_until' => $awayUntil])->save();
        }

        return $rep->fresh();
    }

    private function pick(): ?User
    {
        return $this->asTenant(
            $this->t['tenant'],
            fn () => app(WorkloadBalancer::class)->pick($this->t['tenant']),
        );
    }

    private function roundRobin(): ?User
    {
        return $this->asTenant(
            $this->t['tenant'],
            fn () => app(LeadAssignmentService::class)->roundRobin($this->t['tenant']),
        );
    }

    public function test_a_rep_who_is_away_is_not_given_the_lead(): void
    {
        $away = $this->rep(now()->addDays(3)->toDateTimeString());
        $here = $this->rep();

        $this->assertSame($here->id, $this->pick()?->id);
        $this->assertNotSame($away->id, $this->pick()?->id);
    }

    /** A date, not a flag: the Sunday somebody forgets is a week of lost leads. */
    public function test_a_rep_whose_leave_has_ended_is_back_in_the_rotation(): void
    {
        $back = $this->rep(now()->subDay()->toDateTimeString());

        $this->assertTrue($back->isAvailable());
        $this->assertSame($back->id, $this->pick()?->id);
    }

    /**
     * The decision that makes this an improvement rather than a new way to lose
     * a lead.
     */
    public function test_a_lead_still_gets_an_owner_when_everyone_is_away(): void
    {
        $this->rep(now()->addDays(3)->toDateTimeString());
        $this->rep(now()->addDays(5)->toDateTimeString());

        $this->assertNotNull($this->pick(), 'an unassigned lead belongs to nobody');
    }

    public function test_round_robin_also_skips_whoever_is_away(): void
    {
        $away = $this->rep(now()->addDays(2)->toDateTimeString());
        $here = $this->rep();

        foreach (range(1, 4) as $_) {
            $this->assertNotSame($away->id, $this->roundRobin()?->id);
        }

        $this->assertSame($here->id, $this->roundRobin()?->id);
    }

    public function test_round_robin_still_answers_when_the_whole_team_is_away(): void
    {
        $this->rep(now()->addDays(2)->toDateTimeString());

        $this->assertNotNull($this->roundRobin());
    }

    /** Leaving the company is still different from being on leave. */
    public function test_a_deactivated_rep_is_never_picked_even_as_a_fallback(): void
    {
        $gone = $this->rep();
        $gone->forceFill(['is_active' => false])->save();

        $away = $this->rep(now()->addDays(3)->toDateTimeString());

        $this->assertSame($away->id, $this->pick()?->id);
        $this->assertNotSame($gone->id, $this->pick()?->id);
    }

    /** An incoming lead still lands on somebody, which is the whole promise. */
    public function test_a_new_lead_is_assigned_to_whoever_is_actually_here(): void
    {
        $away = $this->rep(now()->addDays(3)->toDateTimeString());
        $here = $this->rep();

        $lead = $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => 'عميل',
            'phone_normalized' => '+96650018'.random_int(1000, 9999),
            'status' => 'new',
        ]));

        $chosen = $this->asTenant(
            $this->t['tenant'],
            fn () => app(LeadAssignmentService::class)->byStrategy($this->t['tenant']),
        );

        $this->asTenant(
            $this->t['tenant'],
            fn () => app(LeadAssignmentService::class)->assign($lead, $chosen, null, true),
        );

        $this->assertSame($here->id, $lead->fresh()->assigned_to);
        $this->assertNotSame($away->id, $lead->fresh()->assigned_to);
    }

    public function test_being_away_never_reaches_another_workspace(): void
    {
        $other = $this->provisionTenant('Somebody Else');
        // Their own rep: auto-assignment targets sales and managers, and a
        // freshly provisioned workspace has only an admin.
        $theirRep = $this->makeUser($other['tenant']);

        $this->rep(now()->addDays(3)->toDateTimeString());

        $theirs = $this->asTenant(
            $other['tenant'],
            fn () => app(WorkloadBalancer::class)->pick($other['tenant']),
        );

        $this->assertSame($theirRep->id, $theirs?->id);
        $this->assertSame($other['tenant']->id, $theirs?->tenant_id);
    }

    public function test_a_rep_can_say_when_they_are_back(): void
    {
        $rep = $this->rep();

        $this->actingAs($rep)
            ->patchJson("/api/v1/users/{$rep->uuid}", ['away_until' => now()->addDays(5)->toDateString()])
            ->assertOk();

        $this->assertFalse($rep->fresh()->isAvailable());
    }

    /**
     * Not gated behind an admin the way role and activation are: being on leave
     * is a fact about somebody's week, not a change to their account — and a rep
     * who has to ask an admin to mark it is a rep whose leads keep arriving.
     */
    public function test_a_manager_can_mark_their_rep_away(): void
    {
        $rep = $this->rep();

        $this->actingAs($this->t['admin'])
            ->patchJson("/api/v1/users/{$rep->uuid}", ['away_until' => now()->addDays(2)->toDateString()])
            ->assertOk();

        $this->assertFalse($rep->fresh()->isAvailable());
    }

    public function test_coming_back_early_is_just_clearing_it(): void
    {
        $rep = $this->rep(now()->addDays(5)->toDateTimeString());

        $this->actingAs($rep)
            ->patchJson("/api/v1/users/{$rep->uuid}", ['away_until' => null])
            ->assertOk();

        $this->assertTrue($rep->fresh()->isAvailable());
    }

    /** A date in the past is already over, and is reported as nothing. */
    public function test_a_lapsed_date_is_not_reported_as_being_away(): void
    {
        $rep = $this->rep(now()->subDay()->toDateTimeString());

        $shown = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/users')
            ->assertOk()
            ->json('data.users');

        $row = collect($shown)->firstWhere('id', $rep->uuid);

        $this->assertNotNull($row, 'the rep must be in the list for this to mean anything');
        $this->assertNull($row['away_until']);
    }

    public function test_nobody_can_mark_somebody_in_another_workspace_away(): void
    {
        $other = $this->provisionTenant('Somebody Else');
        $theirRep = $this->makeUser($other['tenant']);

        $this->actingAs($this->t['admin'])
            ->patchJson("/api/v1/users/{$theirRep->uuid}", ['away_until' => now()->addDays(2)->toDateString()])
            ->assertNotFound();

        $this->assertTrue($theirRep->fresh()->isAvailable());
    }
}
