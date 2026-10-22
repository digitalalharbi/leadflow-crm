<?php

namespace App\Domain\Notifications;

use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * "Tell them once."
 *
 * An alert that repeats is an alert that is muted, and a muted alert takes the
 * one that mattered down with it. Every important notification asks here first
 * with a key naming the *thing* — a lead, a rule and a day — and is only sent
 * if nobody has been told about that thing before.
 */
class AlertGate
{
    /** True the first time a key is seen in this workspace, false every time after. */
    public static function once(string $key): bool
    {
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId === null) {
            return false;
        }

        return DB::table('alert_marks')->insertOrIgnore(['tenant_id' => $tenantId, 'key' => mb_substr($key, 0, 190), 'created_at' => now()]) === 1;
    }
}
