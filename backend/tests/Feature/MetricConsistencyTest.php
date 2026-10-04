<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * One word, one measurement.
 *
 * A dashboard that says "qualified: 4" beside "+25% vs the previous period" is
 * making a claim about the same quantity twice. When the two counts are built
 * from different predicates — one from the pipeline stage, one from whether the
 * qualification form was filled — the percentage is arithmetic performed on two
 * unrelated numbers, and it is worse than showing nothing, because it looks
 * like information.
 *
 * These tests exist to make that drift fail loudly rather than ship quietly.
 */
class MetricConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_change_arrow_compares_the_same_measurement_it_shows(): void
    {
        $t = $this->provisionTenant('Consistent Co');

        $this->asTenant($t['tenant'], function () {
            // Two windows of identical shape, thirty days apart. If current and
            // previous are the same measurement, every figure must match.
            $this->seedWindow(now()->subDays(5), 'a');
            $this->seedWindow(now()->subDays(45), 'b');
        });

        $body = $this->actingAs($t['admin'])
            ->getJson('/api/v1/dashboard?date_from='.now()->subDays(30)->toDateString()
                .'&date_to='.now()->toDateString())
            ->assertOk()
            ->json('data');

        $summary = $body['summary'];
        $previous = $body['comparison'];

        $this->assertNotNull($previous, 'A filtered dashboard must carry a previous window.');

        foreach (['qualified', 'appointments', 'won'] as $key) {
            $this->assertSame(
                $summary[$key],
                $previous[$key],
                "Identical windows disagree on '{$key}', so the current figure and the one it is ".
                'compared against are not the same measurement.',
            );
        }

        $this->assertSame($summary['hot_leads'], $previous['hot'],
            'The hot count and its comparison are not the same measurement.');
    }

    /**
     * A project card draws a progression, so its stages have to be cumulative:
     * a lead that reached an appointment was qualified on the way, whether or
     * not anyone filled the form afterwards.
     */
    public function test_a_project_cannot_report_more_appointments_than_qualified_leads(): void
    {
        $t = $this->provisionTenant('Projects Co');

        $project = $this->asTenant($t['tenant'], function () {
            $project = Project::create(['name' => 'برج الاختبار', 'status' => 'active']);

            foreach (['qualified', 'appointment', 'appointment', 'won'] as $i => $status) {
                Lead::create([
                    'full_name' => 'عميل '.$i,
                    'phone_normalized' => '+96650000100'.$i,
                    'project_id' => $project->id,
                    'status' => $status,
                ]);
            }

            return $project;
        });

        $metrics = collect(
            $this->actingAs($t['admin'])->getJson('/api/v1/projects')->assertOk()->json('data.metrics')
        )->firstWhere('id', $project->uuid);

        $this->assertSame(4, $metrics['leads']);
        // Every one of them reached qualified, and none filled the form.
        $this->assertSame(4, $metrics['qualified']);
        $this->assertSame(3, $metrics['appointments']);
        $this->assertSame(1, $metrics['won']);

        $this->assertGreaterThanOrEqual($metrics['appointments'], $metrics['qualified'],
            'A project reports more appointments than qualified leads, which cannot happen.');
        $this->assertGreaterThanOrEqual($metrics['won'], $metrics['appointments'],
            'A project reports more sales than appointments, which cannot happen.');
    }

    private function seedWindow(Carbon $when, string $tag): void
    {
        $statuses = ['new', 'contacted', 'qualified', 'appointment', 'won', 'lost'];

        foreach ($statuses as $i => $status) {
            Lead::create([
                'full_name' => "عميل {$tag}{$i}",
                'phone_normalized' => '+9665'.($tag === 'a' ? '1' : '2').'0000'.$i.'0',
                'status' => $status,
                'temperature' => $i % 2 === 0 ? 'hot' : 'warm',
            ])->forceFill(['created_at' => $when, 'updated_at' => $when])->saveQuietly();
        }
    }
}
