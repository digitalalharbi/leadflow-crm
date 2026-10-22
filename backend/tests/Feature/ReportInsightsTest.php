<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The headline above the tables.
 *
 * What these protect is restraint. An insight is a claim a manager will repeat in
 * a meeting, so none is made from a handful of leads, none from a difference too
 * small to be one, and every one carries the numbers the tables can confirm.
 */
class ReportInsightsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Insight Co');
        $this->t['tenant']->settings->forceFill(['first_contact_target_minutes' => 30])->save();
    }

    private function leads(int $n, array $attributes = [], int $daysAgo = 5): void
    {
        $this->asTenant($this->t['tenant'], function () use ($n, $attributes, $daysAgo) {
            foreach (range(1, $n) as $i) {
                $lead = Lead::create(array_merge([
                    'full_name' => 'عميل '.random_int(1000, 9999),
                    'phone_normalized' => '+9665'.random_int(10000000, 99999999),
                    'status' => 'new',
                    'platform' => 'meta',
                ], $attributes));
                $lead->forceFill(['created_at' => now()->subDays($daysAgo)->subMinutes($i)])->saveQuietly();
            }
        });
    }

    private function insights(?array $as = null): array
    {
        return $this->actingAs(($as ?? $this->t)['admin'])->getJson('/api/v1/reports/insights')->assertOk()->json('data.insights');
    }

    private function find(string $key): ?array
    {
        return collect($this->insights())->firstWhere('key', $key);
    }

    // ------------------------------------------------------------- restraint

    public function test_an_empty_workspace_says_nothing(): void
    {
        $this->assertSame([], $this->insights());
    }

    public function test_a_handful_of_leads_is_not_a_finding(): void
    {
        $this->leads(3, ['platform' => 'tiktok', 'status' => 'qualified']);
        $this->leads(3, ['platform' => 'meta']);

        $this->assertNull($this->find('best_source'));
        $this->assertNull($this->find('biggest_leak'));
    }

    // -------------------------------------------------------------- momentum

    public function test_a_first_month_has_a_count_and_no_change(): void
    {
        $this->leads(8);

        $insight = $this->find('leads_this_month');

        $this->assertSame(8, $insight['values']['count']);
        $this->assertNull($this->find('leads_up'));
    }

    public function test_growth_is_reported_with_both_numbers(): void
    {
        $this->leads(12, [], daysAgo: 5);
        $this->leads(6, [], daysAgo: 40);

        $insight = $this->find('leads_up');

        $this->assertSame('good', $insight['tone']);
        $this->assertSame(12, $insight['values']['count']);
        $this->assertSame(6, $insight['values']['previous']);
        $this->assertEquals(100, $insight['values']['change']);
    }

    public function test_a_decline_is_a_warning(): void
    {
        $this->leads(6, [], daysAgo: 5);
        $this->leads(12, [], daysAgo: 40);

        $insight = $this->find('leads_down');

        $this->assertSame('warn', $insight['tone']);
        $this->assertEquals(50, $insight['values']['change']);
    }

    public function test_a_change_too_small_to_be_one_is_called_steady(): void
    {
        $this->leads(10, [], daysAgo: 5);
        $this->leads(10, [], daysAgo: 40);

        $this->assertNotNull($this->find('leads_steady'));
        $this->assertNull($this->find('leads_up'));
        $this->assertNull($this->find('leads_down'));
    }

    // ----------------------------------------------------------- best source

    public function test_the_best_source_is_named_against_the_worst(): void
    {
        $this->leads(6, ['platform' => 'tiktok', 'status' => 'qualified']);
        $this->leads(6, ['platform' => 'meta', 'status' => 'new']);

        $insight = $this->find('best_source');

        $this->assertSame('tiktok', $insight['values']['best']);
        $this->assertSame('meta', $insight['values']['worst']);
        $this->assertEquals(100, $insight['values']['best_rate']);
        $this->assertEquals(0, $insight['values']['worst_rate']);
    }

    public function test_two_sources_that_are_about_the_same_are_not_ranked(): void
    {
        $this->leads(10, ['platform' => 'tiktok', 'status' => 'qualified']);
        $this->leads(10, ['platform' => 'meta', 'status' => 'qualified']);

        $this->assertNull($this->find('best_source'));
    }

    public function test_one_source_has_nothing_to_be_better_than(): void
    {
        $this->leads(10, ['platform' => 'tiktok', 'status' => 'qualified']);

        $this->assertNull($this->find('best_source'));
    }

    // -------------------------------------------------------- response speed

    public function test_slow_responses_are_a_warning_with_the_median(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            foreach (range(1, 6) as $i) {
                $lead = Lead::create(['full_name' => "ب$i", 'phone_normalized' => '+9665'.random_int(10000000, 99999999), 'status' => 'contacted']);
                $lead->forceFill(['created_at' => now()->subDays(3), 'first_contact_at' => now()->subDays(3)->addMinutes(180)])->saveQuietly();
            }
        });

        $insight = $this->find('response_slow');

        $this->assertSame('warn', $insight['tone']);
        $this->assertSame(180, $insight['values']['median']);
        $this->assertSame(30, $insight['values']['target']);
        $this->assertEquals(100, $insight['values']['slow_share']);
    }

    public function test_fast_responses_are_good_news(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            foreach (range(1, 6) as $i) {
                $lead = Lead::create(['full_name' => "س$i", 'phone_normalized' => '+9665'.random_int(10000000, 99999999), 'status' => 'contacted']);
                $lead->forceFill(['created_at' => now()->subDays(3), 'first_contact_at' => now()->subDays(3)->addMinutes(5)])->saveQuietly();
            }
        });

        $this->assertSame('good', $this->find('response_fast')['tone']);
    }

    // ------------------------------------------------------------ the leak

    public function test_the_step_that_loses_the_most_is_named(): void
    {
        // Twelve arrive, only two are ever contacted: the leak is at the door.
        $this->leads(10, ['status' => 'new']);
        $this->asTenant($this->t['tenant'], function () {
            foreach (range(1, 2) as $i) {
                $lead = Lead::create(['full_name' => "ج$i", 'phone_normalized' => '+9665'.random_int(10000000, 99999999), 'status' => 'contacted']);
                $lead->forceFill(['created_at' => now()->subDays(2), 'first_contact_at' => now()])->saveQuietly();
            }
        });

        $insight = $this->find('biggest_leak');

        $this->assertSame('new', $insight['values']['from']);
        $this->assertSame('contacted', $insight['values']['to']);
        $this->assertEquals(83, $insight['values']['lost']);
    }

    // --------------------------------------------------------------- access

    public function test_a_rep_cannot_read_the_reports(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->actingAs($rep)->getJson('/api/v1/reports/insights')->assertForbidden();
    }

    public function test_one_workspace_never_reads_anothers_numbers(): void
    {
        $other = $this->provisionTenant('Elsewhere');
        $this->asTenant($other['tenant'], function () {
            foreach (range(1, 12) as $i) {
                Lead::create(['full_name' => "غ$i", 'phone_normalized' => '+9665'.random_int(10000000, 99999999), 'status' => 'new', 'platform' => 'meta']);
            }
        });

        $this->assertSame([], $this->insights());
    }
}
