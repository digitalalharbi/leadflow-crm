<?php

namespace Tests\Feature;

use App\Domain\Leads\WorkloadBalancer;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who gets the next lead.
 *
 * The question this answers is not "whose turn is it" but "who can actually
 * work it now" — the distinction round robin cannot make, and the reason a
 * backlog builds on one desk while another sits empty.
 */
class WorkloadBalancerTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private WorkloadBalancer $balancer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Workload Co');
        $this->balancer = app(WorkloadBalancer::class);
    }

    private function leadsFor(User $user, int $count, string $status = 'new'): void
    {
        $this->asTenant($this->t['tenant'], function () use ($user, $count, $status) {
            for ($i = 0; $i < $count; $i++) {
                Lead::create([
                    'full_name' => 'عميل '.$i,
                    'phone_normalized' => '+9665'.random_int(10000000, 99999999),
                    'status' => $status,
                    'assigned_to' => $user->id,
                ]);
            }
        });
    }

    public function test_the_least_loaded_rep_gets_the_next_lead(): void
    {
        $busy = $this->makeUser($this->t['tenant']);
        $free = $this->makeUser($this->t['tenant']);

        $this->leadsFor($busy, 8);
        $this->leadsFor($free, 1);

        $this->assertSame($free->id, $this->balancer->pick($this->t['tenant'])?->id);
    }

    /**
     * The distinction that matters: closing deals is work, and a rep who has
     * closed thirty must not be treated as the busiest person on the team.
     */
    public function test_closed_leads_do_not_count_against_a_rep(): void
    {
        $closer = $this->makeUser($this->t['tenant']);
        $other = $this->makeUser($this->t['tenant']);

        $this->leadsFor($closer, 20, 'won');
        $this->leadsFor($other, 3);

        $this->assertSame($closer->id, $this->balancer->pick($this->t['tenant'])?->id);
    }

    public function test_two_idle_reps_alternate_rather_than_one_taking_everything(): void
    {
        $first = $this->makeUser($this->t['tenant']);
        $second = $this->makeUser($this->t['tenant']);

        $picked = $this->balancer->pick($this->t['tenant']);
        $this->assertNotNull($picked);

        $this->leadsFor($picked, 1);

        $next = $this->balancer->pick($this->t['tenant']);
        $this->assertNotSame($picked->id, $next?->id, 'The same rep was picked twice while another sat idle.');
        $this->assertContains($next?->id, [$first->id, $second->id]);
    }

    /** Someone deactivated is not a candidate, however empty their desk. */
    public function test_an_inactive_rep_is_never_picked(): void
    {
        $active = $this->makeUser($this->t['tenant']);
        $away = $this->makeUser($this->t['tenant']);
        $away->forceFill(['is_active' => false])->save();

        $this->leadsFor($active, 50);

        $this->assertSame($active->id, $this->balancer->pick($this->t['tenant'])?->id);
    }

    public function test_a_workspace_with_nobody_to_assign_returns_nothing(): void
    {
        $empty = $this->provisionTenant('Nobody Here');

        $this->asTenant($empty['tenant'], function () use ($empty) {
            // The provisioned admin is not a sales role.
            User::where('tenant_id', $empty['tenant']->id)
                ->update(['role' => 'admin']);
        });

        $this->assertNull($this->balancer->pick($empty['tenant']));
    }

    /** Another workspace's reps must never be offered this lead. */
    public function test_it_never_crosses_tenants(): void
    {
        $other = $this->provisionTenant('Someone Else');
        $theirRep = $this->makeUser($other['tenant']);

        $ours = $this->makeUser($this->t['tenant']);
        $this->leadsFor($ours, 99);

        $picked = $this->balancer->pick($this->t['tenant']);

        $this->assertNotSame($theirRep->id, $picked?->id);
        $this->assertSame($ours->id, $picked?->id);
    }
}
