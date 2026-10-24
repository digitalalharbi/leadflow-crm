<?php

namespace App\Domain\Reports;

use App\Domain\Billing\Entitlements;
use App\Models\ReportShare;
use App\Models\Tenant;

/**
 * Whose name is on the page. The workspace's own branding by default; a report may override the colour, the logo and
 * the footer note when the plan includes white-label reports. Never the platform's name unless nothing else is set.
 */
class ReportBranding
{
    /** @return array{name:?string,color:string,logo_url:?string,footer:?string,project_logo_url:?string,powered_by:bool} */
    public static function workspace(ReportShare $share, ?Tenant $tenant): array
    {
        $brand = $share->branding ?? [];
        $own = $tenant?->branding;

        return [
            'name' => $brand['name'] ?? $own?->company_name ?? $tenant?->name,
            'color' => self::colour($brand['color'] ?? null) ?? self::colour($own?->primary_color) ?? '#2563eb',
            'logo_url' => self::url($brand['logo_url'] ?? null) ?? $own?->logo_url,
            'footer' => isset($brand['footer']) ? mb_substr(strip_tags((string) $brand['footer']), 0, 200) : null,
            'project_logo_url' => self::url($brand['project_logo_url'] ?? null),
            // "Powered by مهتم" goes only where the plan lets the workspace remove it.
            'powered_by' => ! (($brand['hide_mohtam'] ?? false) && $tenant && Entitlements::for($tenant)->has('white_label_reports')),
        ];
    }

    /** Only an https address: no data: or javascript: URL ever reaches an image tag. */
    public static function url(?string $value): ?string
    {
        return $value !== null && preg_match('#^https://[^\s<>"\']{3,500}$#i', $value) ? $value : null;
    }

    private static function colour(?string $value): ?string
    {
        return $value !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : null;
    }
}
