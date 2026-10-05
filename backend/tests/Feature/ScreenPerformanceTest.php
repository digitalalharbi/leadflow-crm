<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The screens rebuilt for v0.4.0, measured rather than assumed.
 *
 * Every one of them learned to show a figure per row — a project's funnel, a
 * tenant's thirty-day volume — and that is exactly the change that turns one
 * query into one query per row without anyone noticing on a demo workspace.
 * So the assertion is not a number of milliseconds, which varies by machine,
 * but that the cost does not grow with the data.
 */
class ScreenPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_project_list_costs_the_same_with_one_project_or_twenty(): void
    {
        $t = $this->provisionTenant('Projects Co');

        $this->asTenant($t['tenant'], fn () => $this->makeProjects(1));

        $small = $this->countQueries(fn () => $this->actingAs($t['admin'])
            ->getJson('/api/v1/projects')->assertOk());

        $this->asTenant($t['tenant'], fn () => $this->makeProjects(19, from: 1));

        $large = $this->countQueries(fn () => $this->actingAs($t['admin'])
            ->getJson('/api/v1/projects')->assertOk());

        $this->assertSame($small, $large, "The project list went from {$small} to {$large} queries for 20 projects instead of 1 — the per-project metrics are being fetched in a loop.");
    }

    public function test_the_platform_console_costs_the_same_with_one_tenant_or_ten(): void
    {
        $owner = User::create([
            'tenant_id' => null,
            'name' => 'Platform Owner',
            'email' => 'owner@platform.test',
            'password' => 'password123',
            'role' => Role::SuperAdmin,
        ]);

        $this->provisionTenant('Only One');

        $small = $this->countQueries(fn () => $this->actingAs($owner)
            ->getJson('/api/v1/super-admin/tenants')->assertOk());

        for ($i = 0; $i < 9; $i++) {
            $this->provisionTenant('Client '.$i);
        }

        $large = $this->countQueries(fn () => $this->actingAs($owner)
            ->getJson('/api/v1/super-admin/tenants')->assertOk());

        $this->assertSame($small, $large, "The tenant list went from {$small} to {$large} queries for 10 tenants instead of 1 — usage or branding is being loaded per row.");
    }

    public function test_the_dashboard_costs_the_same_with_ten_leads_or_three_hundred(): void
    {
        $t = $this->provisionTenant('Busy Co');

        $this->asTenant($t['tenant'], fn () => $this->makeLeads(10));

        $small = $this->countQueries(fn () => $this->actingAs($t['admin'])
            ->getJson('/api/v1/dashboard')->assertOk());

        $this->asTenant($t['tenant'], fn () => $this->makeLeads(290, from: 10));

        $large = $this->countQueries(fn () => $this->actingAs($t['admin'])
            ->getJson('/api/v1/dashboard')->assertOk());

        /*
         * Not equality: with ten leads some of the mini-lists come back empty
         * and their eager loads never run, so a bigger workspace can legally
         * cost a query or two more. What may not happen is the count tracking
         * the row count, which is what a loop would do.
         */
        $this->assertLessThanOrEqual($small + 3, $large, "The dashboard went from {$small} to {$large} queries for 30x the leads — an analytic is iterating rows instead of aggregating them.");
    }

    /** The comparison window doubles the analytics; it must not multiply them. */
    public function test_a_dashboard_with_a_date_range_stays_within_a_fixed_budget(): void
    {
        $t = $this->provisionTenant('Ranged Co');

        $this->asTenant($t['tenant'], fn () => $this->makeLeads(60));

        $queries = $this->countQueries(fn () => $this->actingAs($t['admin'])
            ->getJson('/api/v1/dashboard?date_from='.now()->subDays(30)->toDateString()
                .'&date_to='.now()->toDateString())->assertOk());

        $this->assertLessThan(30, $queries, "The filtered dashboard issued {$queries} queries.");
    }

    /**
     * The queue ranks in PHP, which only stays honest if the candidate window
     * is one query. A relation read inside the ranking loop would be fifty.
     */
    public function test_the_work_queue_costs_the_same_with_five_leads_or_fifty(): void
    {
        $t = $this->provisionTenant('Queue Perf');

        $this->asTenant($t['tenant'], fn () => $this->makeLeads(5));

        $small = $this->countQueries(fn () => $this->actingAs($t['admin'])
            ->getJson('/api/v1/work/next')->assertOk());

        $this->asTenant($t['tenant'], fn () => $this->makeLeads(45, from: 5));

        $large = $this->countQueries(fn () => $this->actingAs($t['admin'])
            ->getJson('/api/v1/work/next')->assertOk());

        /*
         * Not equality: with five leads the queue can come back empty and skip
         * loading a result at all, so a fuller workspace can legitimately cost a
         * query or two more. What must not happen is the count following the
         * row count, which is what a relation read inside the ranking loop
         * would do — fifty leads would mean fifty queries, not two.
         */
        $this->assertLessThanOrEqual($small + 2, $large,
            "The work queue went from {$small} to {$large} queries for ten times the leads.");
    }

    private function makeProjects(int $count, int $from = 0): void
    {
        for ($i = $from; $i < $from + $count; $i++) {
            $project = Project::create(['name' => 'Project '.$i, 'status' => 'active']);

            Lead::create([
                'full_name' => 'Lead for '.$i,
                'phone_normalized' => '+96650'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'project_id' => $project->id,
                'status' => 'qualified',
            ]);
        }
    }

    private function makeLeads(int $count, int $from = 0): void
    {
        $statuses = ['new', 'contacted', 'qualified', 'appointment', 'won', 'lost'];

        for ($i = $from; $i < $from + $count; $i++) {
            Lead::create([
                'full_name' => 'Lead '.$i,
                'phone_normalized' => '+96651'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'status' => $statuses[$i % count($statuses)],
                'platform' => ['meta', 'google', 'tiktok'][$i % 3],
            ]);
        }
    }

    private function countQueries(callable $run): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $run();

        return $count;
    }
}
