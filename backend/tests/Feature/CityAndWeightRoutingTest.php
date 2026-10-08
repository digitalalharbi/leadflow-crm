<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Services\Leads\LeadAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where a rep sells, and how much of the load they carry.
 *
 * Both default to "no opinion": an empty coverage list means every city, and a
 * weight of one means an equal share. A workspace that never touches either
 * keeps exactly the behaviour it had, which is the only honest default for
 * something that decides who talks to a customer.
 *
 * Most of what is tested here is the refusal to narrow. A lead from a city
 * nobody claims, or with no city at all, still needs an owner — and giving it
 * to somebody who covers the country generally is better than giving it to no
 * one and finding it on the data-quality screen next week.
 */
class CityAndWeightRoutingTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Coverage Co');
        $this->t['tenant']->settings->forceFill([
            'assignment_mode' => 'round_robin',
            'assignment_strategy' => 'workload',
        ])->save();
    }

    private function rep(array $attributes = []): User
    {
        $rep = $this->makeUser($this->t['tenant']);

        if ($attributes !== []) {
            $rep->forceFill($attributes)->save();
        }

        return $rep->fresh();
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل',
            'phone_normalized' => '+96650020'.random_int(1000, 9999),
            'status' => 'new',
        ], $attributes)));
    }

    private function pickFor(Lead $lead): ?User
    {
        return $this->asTenant(
            $this->t['tenant'],
            fn () => app(LeadAssignmentService::class)->pickAssignee($this->t['tenant'], $lead),
        );
    }

    public function test_a_lead_goes_to_a_rep_who_sells_in_that_city(): void
    {
        $riyadh = $this->rep(['covers_cities' => ['الرياض']]);
        $jeddah = $this->rep(['covers_cities' => ['جدة']]);

        $this->assertSame($riyadh->id, $this->pickFor($this->lead(['city' => 'الرياض']))?->id);
        $this->assertSame($jeddah->id, $this->pickFor($this->lead(['city' => 'جدة']))?->id);
    }

    /** A rep can cover more than one city, which is the ordinary case. */
    public function test_a_rep_can_cover_several_cities(): void
    {
        $both = $this->rep(['covers_cities' => ['الرياض', 'الدمام']]);
        $this->rep(['covers_cities' => ['جدة']]);

        $this->assertSame($both->id, $this->pickFor($this->lead(['city' => 'الدمام']))?->id);
    }

    /**
     * The value is whatever the customer typed into an ad form, so the match
     * forgives spacing and case. It does not try to forgive spelling — guessing
     * wrong routes a lead to the wrong person silently.
     */
    public function test_the_match_forgives_spacing_and_case(): void
    {
        $riyadh = $this->rep(['covers_cities' => ['  الرياض ']]);
        $this->rep(['covers_cities' => ['جدة']]);

        $this->assertSame($riyadh->id, $this->pickFor($this->lead(['city' => 'الرياض']))?->id);

        $london = $this->rep(['covers_cities' => ['Riyadh']]);
        $this->assertNotNull($this->pickFor($this->lead(['city' => 'riyadh'])));
        $this->assertContains(
            $this->pickFor($this->lead(['city' => 'RIYADH']))?->id,
            [$riyadh->id, $london->id],
        );
    }

    /** Nobody has said anything, so nothing narrows. */
    public function test_coverage_nobody_set_changes_nothing(): void
    {
        $only = $this->rep();

        $this->assertSame($only->id, $this->pickFor($this->lead(['city' => 'أبها']))?->id);
    }

    /**
     * The field is optional on most ad platform forms, and a lead with a blank
     * city must not become a lead nobody covers.
     */
    public function test_a_lead_with_no_city_still_gets_an_owner(): void
    {
        $this->rep(['covers_cities' => ['الرياض']]);

        $this->assertNotNull($this->pickFor($this->lead()));
    }

    /** A city nobody claims is still a customer waiting for a phone call. */
    public function test_a_city_nobody_covers_falls_through_rather_than_holding_the_lead(): void
    {
        $this->rep(['covers_cities' => ['الرياض']]);
        $this->rep(['covers_cities' => ['جدة']]);

        $this->assertNotNull($this->pickFor($this->lead(['city' => 'تبوك'])));
    }

    /** Someone who covers everywhere is a candidate for every city. */
    public function test_a_rep_with_no_coverage_set_covers_everywhere(): void
    {
        $anywhere = $this->rep();
        $this->rep(['covers_cities' => ['جدة']]);

        $this->assertSame($anywhere->id, $this->pickFor($this->lead(['city' => 'تبوك']))?->id);
    }

    /**
     * A senior closer on three carries three leads for every one an ordinary
     * rep takes — so the comparison is load per unit of weight, not a count.
     */
    public function test_a_heavier_rep_carries_proportionally_more(): void
    {
        $senior = $this->rep(['assignment_weight' => 3]);
        $junior = $this->rep(['assignment_weight' => 1]);

        // Two each: the senior is at 2/3 of capacity, the junior at 2/1.
        foreach (range(1, 2) as $_) {
            $this->lead(['assigned_to' => $senior->id, 'status' => 'contacted']);
            $this->lead(['assigned_to' => $junior->id, 'status' => 'contacted']);
        }

        $this->assertSame($senior->id, $this->pickFor($this->lead())?->id);
    }

    /** Past their share, the heavier rep stops being the obvious choice. */
    public function test_a_heavier_rep_is_not_a_bottomless_pit(): void
    {
        $senior = $this->rep(['assignment_weight' => 3]);
        $junior = $this->rep(['assignment_weight' => 1]);

        foreach (range(1, 6) as $_) {
            $this->lead(['assigned_to' => $senior->id, 'status' => 'contacted']);
        }

        $this->assertSame($junior->id, $this->pickFor($this->lead())?->id);
    }

    /** Every weight at one is the count it always was. */
    public function test_equal_weights_behave_exactly_as_before(): void
    {
        $busy = $this->rep();
        $free = $this->rep();

        foreach (range(1, 3) as $_) {
            $this->lead(['assigned_to' => $busy->id, 'status' => 'contacted']);
        }

        $this->assertSame($free->id, $this->pickFor($this->lead())?->id);
    }

    /** Under round robin the weight is places in the rotation, not a divisor. */
    public function test_weight_means_the_same_thing_under_round_robin(): void
    {
        $this->t['tenant']->settings->forceFill(['assignment_strategy' => 'round_robin'])->save();

        $heavy = $this->rep(['assignment_weight' => 2]);
        $light = $this->rep(['assignment_weight' => 1]);

        $picked = collect(range(1, 6))->map(fn () => $this->pickFor($this->lead())?->id);

        $this->assertSame(4, $picked->filter(fn ($id) => $id === $heavy->id)->count());
        $this->assertSame(2, $picked->filter(fn ($id) => $id === $light->id)->count());
    }

    /** Knowing the project beats covering the city: the customer asks about the project. */
    public function test_the_project_team_wins_over_city_coverage(): void
    {
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));

        $onProject = $this->rep(['covers_cities' => ['جدة']]);
        $inRiyadh = $this->rep(['covers_cities' => ['الرياض']]);

        $project->team()->sync([$onProject->id]);

        $chosen = $this->pickFor($this->lead(['project_id' => $project->id, 'city' => 'الرياض']));

        $this->assertSame($onProject->id, $chosen?->id);
        $this->assertNotSame($inRiyadh->id, $chosen?->id);
    }

    /** Away still wins over everything: an absent rep cannot work a lead. */
    public function test_coverage_does_not_override_being_away(): void
    {
        $away = $this->rep(['covers_cities' => ['الرياض'], 'away_until' => now()->addDays(3)]);
        $here = $this->rep(['covers_cities' => ['جدة']]);

        $chosen = $this->pickFor($this->lead(['city' => 'الرياض']));

        $this->assertSame($here->id, $chosen?->id);
        $this->assertNotSame($away->id, $chosen?->id);
    }

    public function test_a_manager_can_set_coverage_and_weight(): void
    {
        $rep = $this->rep();

        $this->actingAs($this->t['admin'])
            ->patchJson("/api/v1/users/{$rep->uuid}", [
                'covers_cities' => ['الرياض', 'الخرج'],
                'assignment_weight' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.user.assignment_weight', 3);

        $fresh = $rep->fresh();

        $this->assertSame(['الرياض', 'الخرج'], $fresh->covers_cities);
        $this->assertSame(3, $fresh->assignmentWeight());
        $this->assertTrue($fresh->coversCity('الخرج'));
        $this->assertFalse($fresh->coversCity('جدة'));
    }

    /**
     * A rep who could widen their own weight could quietly take the whole
     * pipeline, so this is the workspace's call and not theirs — unlike being
     * on leave, which is a fact about their own week.
     */
    public function test_a_rep_cannot_widen_their_own_share(): void
    {
        $rep = $this->rep();

        $this->actingAs($rep)
            ->patchJson("/api/v1/users/{$rep->uuid}", ['assignment_weight' => 9])
            ->assertStatus(422);

        $this->assertSame(1, $rep->fresh()->assignmentWeight());
    }

    public function test_a_rep_cannot_choose_their_own_cities(): void
    {
        $rep = $this->rep();

        $this->actingAs($rep)
            ->patchJson("/api/v1/users/{$rep->uuid}", ['covers_cities' => ['الرياض']])
            ->assertStatus(422);

        $this->assertSame([], $rep->fresh()->covers_cities ?? []);
    }

    /** Past ten it is not a weighting, it is a rule that one person takes everything. */
    public function test_an_absurd_weight_is_refused(): void
    {
        $rep = $this->rep();

        $this->actingAs($this->t['admin'])
            ->patchJson("/api/v1/users/{$rep->uuid}", ['assignment_weight' => 500])
            ->assertStatus(422);
    }

    public function test_clearing_coverage_hands_the_rep_back_every_city(): void
    {
        $rep = $this->rep(['covers_cities' => ['جدة']]);

        $this->actingAs($this->t['admin'])
            ->patchJson("/api/v1/users/{$rep->uuid}", ['covers_cities' => []])
            ->assertOk();

        $this->assertTrue($rep->fresh()->coversCity('تبوك'));
    }
}
