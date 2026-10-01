<?php

namespace Tests\Feature;

use App\Enums\LeadTemperature;
use App\Enums\QualificationStatus;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Qualifying a lead is the one minute of work that decides who gets called
 * first, so the number it produces has to be defensible: the same answers
 * always give the same score, and every point is attributable to a rule.
 */
class QualificationTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Lead $lead;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Qualification Co');

        [$this->project, $this->lead] = $this->asTenant($this->t['tenant'], function () {
            $project = Project::create(['name' => 'برج الواحة', 'starting_price' => 1_000_000]);

            $lead = Lead::create([
                'full_name' => 'نايف المؤهل',
                'phone' => '0552223333',
                'phone_normalized' => '+966552223333',
                'project_id' => $project->id,
            ]);

            return [$project, $lead->refresh()];
        });
    }

    public function test_a_full_qualification_makes_a_lead_hot(): void
    {
        $response = $this->qualify([
            'purchase_purpose' => 'residence',
            'budget_max' => 1_200_000,
            'payment_method' => 'financing',
            'financing_status' => 'approved',
            'preferred_property_type' => 'شقة',
            'purchase_timeline' => 'within_month',
            'appointment_interest' => true,
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.lead.temperature', 'hot')
            ->assertJsonPath('data.lead.qualification_status', 'qualified');

        $this->assertGreaterThanOrEqual(70, $this->lead->refresh()->score);
        $this->assertNotNull($this->lead->qualified_at);
    }

    public function test_a_budget_below_the_project_price_pulls_the_score_down(): void
    {
        $matched = $this->scoreFor(['budget_max' => 1_100_000, 'purchase_timeline' => 'within_month']);
        $mismatched = $this->scoreFor(['budget_max' => 300_000, 'purchase_timeline' => 'within_month']);

        $this->assertGreaterThan($mismatched, $matched);
        $this->assertLessThan(40, $mismatched, 'A buyer who cannot afford the project is not warm.');
    }

    public function test_a_known_budget_with_no_project_price_is_neither_match_nor_mismatch(): void
    {
        $this->asTenant($this->t['tenant'], fn () => $this->project->update(['starting_price' => null]));

        $score = $this->scoreFor(['budget_max' => 50_000]);

        // Worth something — we know more than we did — but not the full credit
        // of a budget that fits a real price.
        $this->assertGreaterThan(config('scoring.baseline'), $score);
        $this->assertLessThan(config('scoring.baseline') + config('scoring.weights.budget_matched'), $score);
    }

    public function test_the_same_answers_always_give_the_same_score(): void
    {
        $answers = [
            'purchase_purpose' => 'investment',
            'budget_max' => 1_500_000,
            'payment_method' => 'cash',
            'purchase_timeline' => 'within_3_months',
        ];

        $first = $this->scoreFor($answers);
        $second = $this->scoreFor($answers);

        $this->assertSame($first, $second);
    }

    public function test_re_qualifying_never_ratchets_the_score_upward(): void
    {
        // Recomputed from the answers, never accumulated: toggling a field back
        // and forth must not drive a lead to 100.
        $high = $this->scoreFor([
            'budget_max' => 2_000_000,
            'purchase_timeline' => 'within_month',
            'financing_status' => 'approved',
            'appointment_interest' => true,
        ]);

        $low = $this->scoreFor([
            'budget_max' => 100_000,
            'purchase_timeline' => 'over_6_months',
            'financing_status' => 'not_started',
            'appointment_interest' => false,
        ]);

        $this->assertGreaterThan($low, $high);
    }

    public function test_the_score_comes_with_the_reasons_behind_it(): void
    {
        $this->qualify([
            'budget_max' => 1_200_000,
            'purchase_timeline' => 'within_month',
            'appointment_interest' => true,
        ])->assertSuccessful();

        $response = $this->actingAs($this->t['admin'])
            ->getJson("/api/v1/leads/{$this->lead->uuid}/qualification");

        $response->assertSuccessful();

        $keys = collect($response->json('data.reasons'))->pluck('key');

        $this->assertTrue($keys->contains('budget_matched'));
        $this->assertTrue($keys->contains('timeline_soon'));
        $this->assertTrue($keys->contains('wants_appointment'));

        foreach ($response->json('data.reasons') as $reason) {
            $this->assertNotEmpty($reason['label'], 'Every rule must be explainable to a sales manager.');
        }
    }

    public function test_partial_answers_are_partially_qualified(): void
    {
        $this->qualify(['purchase_purpose' => 'residence', 'budget_max' => 900_000])->assertSuccessful();

        $this->assertSame(QualificationStatus::PartiallyQualified, $this->lead->refresh()->qualification_status);
    }

    public function test_an_untouched_lead_is_unqualified(): void
    {
        $this->assertSame(QualificationStatus::Unqualified, $this->lead->qualification_status);
    }

    public function test_qualification_status_is_not_the_pipeline_stage(): void
    {
        $this->qualify([
            'purchase_purpose' => 'residence',
            'budget_max' => 1_200_000,
            'payment_method' => 'cash',
            'financing_status' => 'not_required',
            'preferred_property_type' => 'فيلا',
            'purchase_timeline' => 'within_month',
        ])->assertSuccessful();

        $lead = $this->lead->refresh();

        // Fully qualified, and still sitting in "new" — which is exactly the
        // gap a sales manager needs to see.
        $this->assertSame(QualificationStatus::Qualified, $lead->qualification_status);
        $this->assertSame('new', $lead->status->value);
    }

    public function test_qualifying_writes_the_timeline_entries(): void
    {
        $this->qualify(['budget_max' => 1_200_000, 'appointment_interest' => true])->assertSuccessful();

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $this->lead->id,
            'type' => 'qualification_updated',
        ]);

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $this->lead->id,
            'type' => 'score_changed',
        ]);
    }

    public function test_a_rep_can_only_qualify_their_own_leads(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->actingAs($rep)
            ->patchJson("/api/v1/leads/{$this->lead->uuid}/qualification", ['budget_max' => 1])
            ->assertStatus(403);
    }

    public function test_an_invalid_option_is_refused(): void
    {
        $this->qualify(['purchase_timeline' => 'someday'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('purchase_timeline');
    }

    public function test_the_option_lists_come_from_the_api(): void
    {
        $response = $this->actingAs($this->t['admin'])->getJson('/api/v1/qualification/options');

        $response->assertSuccessful()
            ->assertJsonPath('data.purchase_purpose.0.value', 'residence')
            ->assertJsonPath('data.purchase_purpose.0.label', 'سكن');

        foreach (['purchase_purpose', 'payment_method', 'financing_status', 'purchase_timeline'] as $field) {
            $this->assertNotEmpty($response->json("data.{$field}"));
        }
    }

    public function test_nothing_about_who_the_person_is_moves_the_score(): void
    {
        // A score that moved on a name or a city would be discrimination with a
        // number attached, and it would be invisible once it shipped.
        $base = $this->scoreFor(['budget_max' => 1_200_000, 'purchase_timeline' => 'within_month']);

        $this->asTenant($this->t['tenant'], fn () => $this->lead->forceFill([
            'full_name' => 'Jonathan Fitzgerald-Smythe',
            'city' => 'London',
            'email' => 'jon@example.co.uk',
        ])->saveQuietly());

        $after = $this->scoreFor(['budget_max' => 1_200_000, 'purchase_timeline' => 'within_month']);

        $this->assertSame($base, $after);
    }

    public function test_temperature_follows_the_documented_bands(): void
    {
        $this->assertSame(LeadTemperature::Cold, LeadTemperature::fromScore(39));
        $this->assertSame(LeadTemperature::Warm, LeadTemperature::fromScore(40));
        $this->assertSame(LeadTemperature::Warm, LeadTemperature::fromScore(69));
        $this->assertSame(LeadTemperature::Hot, LeadTemperature::fromScore(70));
    }

    private function qualify(array $answers)
    {
        return $this->actingAs($this->t['admin'])
            ->patchJson("/api/v1/leads/{$this->lead->uuid}/qualification", $answers);
    }

    private function scoreFor(array $answers): int
    {
        $this->qualify($answers)->assertSuccessful();

        return (int) $this->lead->refresh()->score;
    }
}
