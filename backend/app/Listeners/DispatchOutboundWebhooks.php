<?php

namespace App\Listeners;

use App\Domain\Webhooks\OutboundWebhooks;
use App\Events\Contracts\LeadDomainEvent;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * Hands each lead event to the company's outbound webhooks. A failure here is logged and swallowed: telling an outside
 * system about a lead must never stop the lead itself from being saved.
 */
class DispatchOutboundWebhooks
{
    public function __construct(private OutboundWebhooks $webhooks, private TenantContext $tenants) {}

    public function handle(LeadDomainEvent $event): void
    {
        try {
            $this->tenants->withoutScope(fn () => $this->webhooks->dispatch($event));
        } catch (\Throwable $e) {
            Log::warning('webhooks.dispatch_failed', ['event' => $event->name(), 'tenant' => $event->tenantId(), 'error' => class_basename($e)]);
        }
    }
}
