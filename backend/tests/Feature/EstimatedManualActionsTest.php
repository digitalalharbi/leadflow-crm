<?php

namespace Tests\Feature;

use App\Domain\Automation\AutomationInsights;
use App\Models\AutomationActionLog;
use App\Models\AutomationRule;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** An estimate of work assisted: only what was carried out counts, and the arithmetic is the published weights. */
class EstimatedManualActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_actions_really_carried_out_count_toward_the_estimate(): void
    {
        $a = $this->provisionTenant('Alpha');
        $b = $this->provisionTenant('Beta');
        config(['platform.manual_action_minutes' => ['assign' => 2, 'notify' => 1]]);

        $write = function (array $tenant, string $type, string $mode, string $result): void {
            app(TenantContext::class)->withTenant($tenant['tenant'], fn () => AutomationActionLog::create([
                'action_type' => $type, 'mode' => $mode, 'result' => $result, 'happened_at' => now(),
            ]));
        };

        $write($a, 'assign', AutomationRule::MODE_AUTO, AutomationActionLog::COMPLETED);
        $write($a, 'notify', AutomationRule::MODE_AUTO, AutomationActionLog::RECOVERED);
        $write($a, 'assign', AutomationRule::MODE_MONITOR, AutomationActionLog::SKIPPED);
        $write($a, 'assign', AutomationRule::MODE_AUTO, AutomationActionLog::FAILED);
        $write($a, 'assign', AutomationRule::MODE_AUTO, AutomationActionLog::SKIPPED);
        $write($a, 'unweighted_action', AutomationRule::MODE_AUTO, AutomationActionLog::COMPLETED);
        // Another company's work is not this company's estimate.
        $write($b, 'assign', AutomationRule::MODE_AUTO, AutomationActionLog::COMPLETED);

        $summary = app(TenantContext::class)->withTenant($a['tenant'], fn () => app(AutomationInsights::class)->summary());

        $this->assertSame(['actions' => 2, 'minutes' => 3], $summary['estimated_manual']);
    }
}
