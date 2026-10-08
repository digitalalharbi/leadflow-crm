<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Services\Leads\LeadAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A project's leads go to the people who know that project.
 *
 * A developer selling three compounds does not have one sales team, it has
 * three: the rep who knows النرجس can answer what the service charge covers and
 * which units face the park, and the rep who does not has to go and ask.
 * Assignment could not express that — every lead went to whoever was least busy
 * across the whole company, however little they knew about where it came from.
 *
 * Deliberately not a strategy somebody switches on. A project with no team
 * changes nothing, which is what every workspace starts as and what most will
 * stay as.
 */
class ProjectTeamRoutingTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Project $narjis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Projects Co');
        $this->t['tenant']->settings->forceFill(['assignment_mode' => 'round_robin'])->save();

        $this->narjis = $this->asTenant($this->t['tenant'], fn () => Project::create([
            'name' => 'مشروع النرجس',
        ]));
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل',
            'phone_normalized' => '+96650019'.random_int(1000, 9999),
            'status' => 'new',
        ], $attributes)));
    }

    private function pickFor(?Lead $lead): ?User
    {
        return $this->asTenant(
            $this->t['tenant'],
            fn () => app(LeadAssignmentService::class)->pickAssignee($this->t['tenant'], $lead),
        );
    }

    public function test_a_projects_lead_goes_to_that_projects_team(): void
    {
        $onNarjis = $this->makeUser($this->t['tenant']);
        $elsewhere = $this->makeUser($this->t['tenant']);

        $this->narjis->team()->sync([$onNarjis->id]);

        $chosen = $this->pickFor($this->lead(['project_id' => $this->narjis->id]));

        $this->assertSame($onNarjis->id, $chosen?->id);
        $this->assertNotSame($elsewhere->id, $chosen?->id);
    }

    /** Within the team, still the least busy — one rep must not take everything. */
    public function test_it_picks_the_freest_member_of_that_team(): void
    {
        $busy = $this->makeUser($this->t['tenant']);
        $free = $this->makeUser($this->t['tenant']);

        $this->narjis->team()->sync([$busy->id, $free->id]);

        foreach (range(1, 3) as $_) {
            $this->lead(['assigned_to' => $busy->id, 'status' => 'contacted']);
        }

        $this->assertSame($free->id, $this->pickFor($this->lead(['project_id' => $this->narjis->id]))?->id);
    }

    /** No team means no opinion: the workspace's own strategy decides. */
    public function test_a_project_without_a_team_changes_nothing(): void
    {
        $rep = $this->makeUser($this->t['tenant']);

        $this->assertSame($rep->id, $this->pickFor($this->lead(['project_id' => $this->narjis->id]))?->id);
    }

    public function test_a_lead_with_no_project_is_unaffected(): void
    {
        $onNarjis = $this->makeUser($this->t['tenant']);
        $this->narjis->team()->sync([$onNarjis->id]);

        $this->assertNotNull($this->pickFor($this->lead()));
    }

    /**
     * A lead that belongs to nobody is worse than one with a rep who knows the
     * project less well, so a team that is entirely away falls through rather
     * than holding the lead.
     */
    public function test_a_team_that_is_entirely_away_falls_through(): void
    {
        $away = $this->makeUser($this->t['tenant']);
        $away->forceFill(['away_until' => now()->addDays(4)])->save();
        $this->narjis->team()->sync([$away->id]);

        $elsewhere = $this->makeUser($this->t['tenant']);

        $chosen = $this->pickFor($this->lead(['project_id' => $this->narjis->id]));

        $this->assertNotNull($chosen);
        $this->assertSame($elsewhere->id, $chosen->id);
    }

    /** One member away, one here: it stays within the team. */
    public function test_an_away_member_is_skipped_within_the_team(): void
    {
        $away = $this->makeUser($this->t['tenant']);
        $here = $this->makeUser($this->t['tenant']);
        $away->forceFill(['away_until' => now()->addDays(4)])->save();

        $this->narjis->team()->sync([$away->id, $here->id]);

        $this->assertSame($here->id, $this->pickFor($this->lead(['project_id' => $this->narjis->id]))?->id);
    }

    /** An arriving lead is routed, not just one assigned by hand. */
    public function test_an_incoming_lead_lands_on_the_project_team(): void
    {
        $onNarjis = $this->makeUser($this->t['tenant']);
        $this->makeUser($this->t['tenant']);
        $this->narjis->team()->sync([$onNarjis->id]);

        $lead = $this->lead(['project_id' => $this->narjis->id]);
        $chosen = $this->pickFor($lead);

        $this->asTenant(
            $this->t['tenant'],
            fn () => app(LeadAssignmentService::class)->assign($lead, $chosen, null, true),
        );

        $this->assertSame($onNarjis->id, $lead->fresh()->assigned_to);
    }

    public function test_a_project_team_never_reaches_across_workspaces(): void
    {
        $other = $this->provisionTenant('Somebody Else');
        $theirRep = $this->makeUser($other['tenant']);

        // Deliberately wrong on purpose: the pivot has no tenant of its own, so
        // the query has to be the thing that keeps workspaces apart.
        $this->narjis->team()->sync([$theirRep->id]);

        $mine = $this->makeUser($this->t['tenant']);
        $chosen = $this->pickFor($this->lead(['project_id' => $this->narjis->id]));

        $this->assertSame($mine->id, $chosen?->id);
        $this->assertNotSame($theirRep->id, $chosen?->id);
    }

    public function test_a_manager_can_say_who_works_a_project(): void
    {
        $rep = $this->makeUser($this->t['tenant']);

        $team = $this->actingAs($this->t['admin'])
            ->patchJson("/api/v1/projects/{$this->narjis->uuid}", ['team' => [$rep->uuid]])
            ->assertOk()
            ->json('data.project.team');

        $this->assertSame([$rep->uuid], array_column($team, 'id'));
        $this->assertSame($rep->id, $this->pickFor($this->lead(['project_id' => $this->narjis->id]))?->id);
    }

    public function test_clearing_the_team_hands_the_project_back_to_everybody(): void
    {
        $rep = $this->makeUser($this->t['tenant']);
        $this->narjis->team()->sync([$rep->id]);

        $this->actingAs($this->t['admin'])
            ->patchJson("/api/v1/projects/{$this->narjis->uuid}", ['team' => []])
            ->assertOk();

        $this->assertCount(0, $this->narjis->fresh()->team);
    }

    /**
     * The pivot carries no tenant of its own, so the query resolving uuids is
     * the only thing keeping workspaces apart. Naming somebody else's rep must
     * select nobody rather than reach into their workspace.
     */
    public function test_naming_another_workspaces_rep_adds_nobody(): void
    {
        $other = $this->provisionTenant('Somebody Else');
        $theirRep = $this->makeUser($other['tenant']);

        $this->actingAs($this->t['admin'])
            ->patchJson("/api/v1/projects/{$this->narjis->uuid}", ['team' => [$theirRep->uuid]])
            ->assertOk();

        $this->assertCount(0, $this->narjis->fresh()->team);
    }

    public function test_a_sales_rep_cannot_change_who_works_a_project(): void
    {
        $rep = $this->makeUser($this->t['tenant']);

        $this->actingAs($rep)
            ->patchJson("/api/v1/projects/{$this->narjis->uuid}", ['team' => [$rep->uuid]])
            ->assertForbidden();
    }
}
