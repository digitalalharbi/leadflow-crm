<?php

namespace App\Domain\Reports;

use App\Models\ReportShare;
use App\Models\Tenant;

/**
 * Whose name is on the page. The workspace's own branding by default; a report may override the colour, the logo and
 * the footer note when the plan includes white-label reports. Never the platform's name unless nothing else is set.
 */
class ReportBranding
{
    /** @return array{name:?string,color:string,logo_url:?string,footer:?string} */
    public static function workspace(ReportShare $share, ?Tenant $tenant): array
    {
        $brand = $share->branding ?? [];
        $own = $tenant?->branding;

        return [
            'name' => $brand['name'] ?? $own?->company_name ?? $tenant?->name,
            'color' => self::colour($brand['color'] ?? null) ?? self::colour($own?->primary_color) ?? '#2563eb',
            'logo_url' => $brand['logo_url'] ?? $own?->logo_url,
            'footer' => isset($brand['footer']) ? mb_substr((string) $brand['footer'], 0, 200) : null,
        ];
    }

    private static function colour(?string $value): ?string
    {
        return $value !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : null;
    }
}
