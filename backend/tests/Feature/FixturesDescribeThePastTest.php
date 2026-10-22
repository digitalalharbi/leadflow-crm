<?php

namespace Tests\Feature;

use App\Models\Lead;
use Database\Seeders\E2ESeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The demo data has to describe a past that happened.
 *
 * `now()->subDays($i % 21)` is this instant for every twenty-first lead, and
 * the qualification was stamped a flat six hours after arrival — so some leads
 * were qualified several hours into the future. The journey card then reported
 * a lead that had been sitting at "appointment" since the morning as having
 * waited "less than a minute": the component clamping a negative number,
 * correctly, over data that should never have said that.
 *
 * These fixtures are what the screens are read against, in e2e and in every
 * demo, so a figure that is nonsense here is a figure somebody will believe.
 */
class FixturesDescribeThePastTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_seeded_lead_claims_something_that_has_not_happened_yet(): void
    {
        $this->seed(E2ESeeder::class);

        $leads = Lead::withoutGlobalScopes()->get();

        $this->assertGreaterThan(20, $leads->count(), 'the seeder must have run for this to mean anything');

        foreach (['first_contact_at', 'qualified_at', 'won_at', 'lost_at', 'last_contact_at'] as $column) {
            $future = $leads->filter(fn (Lead $lead) => $lead->{$column}?->isFuture());

            $this->assertTrue(
                $future->isEmpty(),
                "{$future->count()} seeded lead(s) carry a {$column} in the future, e.g. ".
                ($future->first()?->{$column}?->toDateTimeString() ?? ''),
            );
        }
    }

    /**
     * Qualification still comes after arrival. Bounding it must not produce the
     * other nonsense — a lead qualified before it existed.
     */
    public function test_a_seeded_qualification_follows_the_arrival_it_belongs_to(): void
    {
        $this->seed(E2ESeeder::class);

        $qualified = Lead::withoutGlobalScopes()->whereNotNull('qualified_at')->get();

        $this->assertNotEmpty($qualified);

        foreach ($qualified as $lead) {
            $this->assertTrue(
                $lead->qualified_at->greaterThanOrEqualTo($lead->created_at),
                "a lead qualified at {$lead->qualified_at} arrived at {$lead->created_at}",
            );
        }
    }
}
