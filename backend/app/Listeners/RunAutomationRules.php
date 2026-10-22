<?php

namespace App\Listeners;

use App\Domain\Automation\AutomationEngine;
use App\Domain\Automation\FollowUpPlanner;
use App\Events\Contracts\LeadDomainEvent;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Runs the rules, on a queue, inside the lead's own tenant.
 *
 * Queued because a webhook must answer the platform in milliseconds — Meta
 * retries a slow endpoint and would deliver the same lead twice. The tenant is
 * set explicitly rather than inherited: a queued job has no request behind it,
 * and the scope fails closed, so without this every rule would see no rules at
 * all and silently do nothing.
 */
class RunAutomationRules implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(
        private AutomationEngine $engine,
        private TenantContext $context,
    ) {}

    public function handle(LeadDomainEvent $event): void
    {
        $tenant = $event->lead()->tenant;

        if (! $tenant) {
            return;
        }

        $this->context->set($tenant);

        $this->engine->handle($event);

        // After the rules, so what the journey scheduled is known: the plan fills in around it.
        try {
            app(FollowUpPlanner::class)->onEvent($event);
        } catch (\Throwable $e) {
            Log::warning('follow_up_plan_failed', ['lead' => $event->lead()->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Automation failing must never look like the lead failing. It is already
     * saved; this records why the rules did not run on it.
     */
    public function failed(LeadDomainEvent $event, \Throwable $e): void
    {
        Log::error('Automation rules failed for a lead.', [
            'lead_id' => $event->lead()->id,
            'tenant_id' => $event->tenantId(),
            'trigger' => $event->name(),
            'error' => $e->getMessage(),
        ]);
    }
}
