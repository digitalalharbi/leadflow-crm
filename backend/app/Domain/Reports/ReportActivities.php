<?php

namespace App\Domain\Reports;

use App\Models\ReportActivity;
use App\Models\ReportShare;
use App\Models\User;
use Illuminate\Http\Request;

/** The trail of a report: written once per event, never edited, and read by the team on the report's page. */
class ReportActivities
{
    /** @param array<string,mixed> $meta */
    public static function record(ReportShare $share, string $action, ?User $by = null, array $meta = []): void
    {
        ReportActivity::withoutGlobalScopes()->create([
            'tenant_id' => $share->tenant_id,
            'report_share_id' => $share->id,
            'action' => $action,
            'actor_id' => $by?->id,
            'meta' => $meta ?: null,
        ]);
    }

    /** A client opened the report. Only the device class is kept — no address, no name. */
    public static function viewed(ReportShare $share, Request $request): void
    {
        $agent = strtolower((string) $request->userAgent());
        $device = match (true) {
            str_contains($agent, 'ipad') || str_contains($agent, 'tablet') => 'tablet',
            str_contains($agent, 'mobile') || str_contains($agent, 'android') || str_contains($agent, 'iphone') => 'mobile',
            $agent !== '' => 'desktop',
            default => 'other',
        };

        self::record($share, 'viewed', null, ['device' => $device]);
    }

    /** @param array<string,mixed> $meta */
    public static function exported(ReportShare $share, ?User $by, string $format, string $via, array $meta = []): void
    {
        self::record($share, 'exported', $by, ['format' => $format, 'via' => $via] + $meta);
    }
}
