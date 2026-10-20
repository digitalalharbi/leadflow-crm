<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Lazy loading is forbidden in every environment but production, so a scheduled command that
 * reads a relation it did not load fails there — quietly, tenant by tenant, as a line of
 * output nobody reads. In staging that meant the SLA ladder never ran. These run the commands
 * the way the scheduler does and require that nothing was swallowed.
 *
 * Laravel only forbids a lazy load on a model that came out of a query with more than one row, so
 * each case has two workspaces and two waiting leads — one of each would pass whatever the code did.
 */
class ScheduledSweepsRunOutsideProductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sla_sweep_reminds_and_escalates_instead_of_failing_on_a_lazy_load(): void
    {
        Queue::fake();
        $t = $this->provisionTenant('Sweep Co');
        $this->provisionTenant('Other Co');
        $rep = $this->makeUser($t['tenant'], Role::Sales);

        $leads = $this->asTenant($t['tenant'], fn () => collect([1, 2])->map(function (int $n) use ($rep) {
            $lead = Lead::create(['full_name' => "عميل ينتظر {$n}", 'phone' => '0551234567', 'phone_normalized' => '+9665512345'.$n.'0', 'status' => 'new', 'assigned_to' => $rep->id]);
            $lead->forceFill(['created_at' => now()->subHours(8)])->saveQuietly();

            return $lead;
        }));

        $this->assertSame(0, Artisan::call('leadflow:sweep-sla'));
        $output = Artisan::output();

        $this->assertStringNotContainsString('lazy load', $output);
        $this->assertStringNotContainsString('Tenant ', $output, 'no tenant reported an error');
        $this->assertMatchesRegularExpression('/SLA sweep: [1-9]\d* reminded, [1-9]\d* escalated/', $output);
        $leads->each(fn (Lead $lead) => $this->assertNotNull(Lead::withoutGlobalScopes()->find($lead->id)->sla_escalated_at));
    }

    public function test_the_routines_command_runs_for_every_tenant_without_a_lazy_load_error(): void
    {
        $this->provisionTenant('Routine Co');
        $this->provisionTenant('Other Routine Co');

        $this->assertSame(0, Artisan::call('leadflow:run-routines'));

        $this->assertStringNotContainsString('lazy load', Artisan::output());
    }
}
