<?php

namespace App\Domain\Billing;

use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Which paid feature a request needs, read from its path — the same way RoutePermission reads which permission it
 * needs. One table, so a new route under a gated area is gated the day it is written.
 *
 * Public capture is never here: a tracking link, a landing page, a lead webhook keep taking leads whatever the plan,
 * because losing a customer's enquiry is worse than any upsell. Only the tools that manage them are gated.
 */
class FeatureGate
{
    public static function for(Request $request): ?string
    {
        $path = trim(preg_replace('#^api/v1/#', '', $request->path()), '/');
        $segments = explode('/', $path);
        $method = $request->method();
        $last = end($segments);

        if ($method === 'GET' && $last === 'export') {
            return 'exports';
        }

        // A background export of the leads list is the same feature as the instant one. The company archive is not
        // gated: a company can always take its own data away.
        if ($path === 'exports' && $method === 'POST' && $request->input('kind') === 'leads') {
            return 'exports';
        }

        return match (true) {
            str_starts_with($path, 'tracking/forms') => 'advanced_forms',
            str_starts_with($path, 'tracking/attribution') => 'attribution',
            str_starts_with($path, 'tracking/destinations') => 'api',
            str_starts_with($path, 'tracking/links') => 'tracking_hub',
            $segments[0] === 'automation', $segments[0] === 'scheduled-actions' => 'automations',
            $segments[0] === 'projects' && ($segments[2] ?? null) === 'automation-pack' => 'automations',
            $segments[0] === 'leads' && ($segments[2] ?? null) === 'automation-runs' => 'automations',
            $segments[0] === 'webhook-endpoints' && $method === 'POST' && count($segments) === 1 => 'api',
            $segments[0] === 'api-keys' && $method === 'POST' => 'api',
            $segments[0] === 'webhook-subscriptions' && $method === 'POST' && count($segments) === 1 => 'api',
            in_array($segments[0], ['report-shares', 'report-schedules', 'agent-reports'], true) => 'advanced_reports',
            $segments[0] === 'reports' && in_array($segments[1] ?? '', ['drivers', 'quality', 'campaign-quality'], true) => 'advanced_reports',
            $path === 'integrations/whatsapp' || str_starts_with($path, 'integrations/whatsapp/') => 'whatsapp',
            $path === 'settings/branding' && $method !== 'GET' && self::rebrands($request) => 'custom_branding',
            default => null,
        };
    }

    /**
     * Renaming the company is not custom branding; changing what the product looks like is. A form that sends the
     * look back unchanged alongside a new company name is not a rebrand either.
     */
    private static function rebrands(Request $request): bool
    {
        $current = app(TenantContext::class)->get()?->branding;

        foreach (['system_name', 'logo_url', 'login_logo_url', 'login_background_url', 'favicon_url', 'primary_color', 'secondary_color'] as $key) {
            if ($request->has($key) && (string) $request->input($key) !== (string) ($current?->{$key} ?? '')) {
                return true;
            }
        }

        return false;
    }
}
