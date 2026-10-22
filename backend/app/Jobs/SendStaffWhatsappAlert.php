<?php

namespace App\Jobs;

use App\Domain\Notifications\StaffWhatsapp;
use App\Models\StaffAlertLog;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** One try at one WhatsApp alert to a member of staff, in its own workspace. Retries are scheduled by StaffWhatsapp, one job per try. */
class SendStaffWhatsappAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $logId, public string $body) {}

    public function handle(StaffWhatsapp $alerts, TenantContext $context): void
    {
        $log = $context->withoutScope(fn () => StaffAlertLog::withoutGlobalScopes()->find($this->logId));
        $tenant = $log ? $context->withoutScope(fn () => Tenant::find($log->tenant_id)) : null;

        if ($tenant !== null) {
            $context->withTenant($tenant, fn () => $alerts->attempt($this->logId, $this->body));
        }
    }
}
