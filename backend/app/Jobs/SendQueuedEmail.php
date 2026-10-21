<?php

namespace App\Jobs;

use App\Domain\Mail\EmailDeliveries;
use App\Models\EmailDelivery;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One try at one email, in its own workspace. Retries are not Laravel's: each attempt is recorded and
 * the wait before the next comes from the delivery's own backoff (see EmailDeliveries), so one try is
 * one job, and the queue's `tries` is one.
 */
class SendQueuedEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $deliveryId) {}

    public function handle(EmailDeliveries $deliveries, TenantContext $context): void
    {
        $delivery = $context->withoutScope(fn () => EmailDelivery::withoutGlobalScopes()->find($this->deliveryId));

        if ($delivery === null) {
            return;
        }

        $tenant = $context->withoutScope(fn () => Tenant::find($delivery->tenant_id));

        if ($tenant !== null) {
            $context->withTenant($tenant, fn () => $deliveries->attempt($this->deliveryId));
        }
    }
}
