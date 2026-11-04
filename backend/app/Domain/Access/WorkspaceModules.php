<?php

namespace App\Domain\Access;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * What the platform owner can switch off for one company. Second in the chain: platform capability, plan, THIS, role
 * permission, project scope, person. Off means the API refuses it and the navigation does not offer it, from this one
 * list. Public capture is never here: a landing page or webhook keeps taking leads whatever is switched off.
 */
class WorkspaceModules
{
    /** module => what it answers to: API prefixes (* is one segment), the page it removes, the permission it reads. */
    public const MODULES = [
        'visits' => ['paths' => ['appointments'], 'page' => 'page.visits', 'permission' => 'visits.view'],
        'reservations' => ['paths' => ['reservations'], 'permission' => 'reservations.view'],
        'units' => ['paths' => ['properties'], 'permission' => 'projects.view'],
        'automation' => ['paths' => ['automation', 'scheduled-actions', 'projects/*/automation-pack'], 'page' => 'page.automation', 'permission' => 'automation.view'],
        'reports' => ['paths' => ['reports', 'report-shares', 'report-schedules', 'agent-reports'], 'page' => 'page.reports', 'permission' => 'analytics.view'],
        'public_sharing' => ['paths' => ['report-shares'], 'permission' => 'analytics.view'],
        'landing' => ['paths' => ['tracking/forms'], 'permission' => 'tracking.view'],
        'lead_sources' => ['paths' => ['projects/*/lead-sources'], 'permission' => 'projects.view'],
        'google_sheets' => ['paths' => ['integrations/sheets'], 'permission' => 'integrations.view'],
        'integrations' => ['paths' => ['integrations'], 'page' => 'page.integrations', 'permission' => 'integrations.view'],
        'exports' => ['paths' => ['exports'], 'permission' => 'leads.view'],
        'api' => ['paths' => ['api-keys'], 'permission' => 'integrations.view'],
        'webhooks' => ['paths' => ['webhook-subscriptions', 'webhook-endpoints', 'webhook-deliveries'], 'permission' => 'integrations.view'],
        'campaigns' => ['paths' => ['campaigns', 'campaign-spend'], 'page' => 'page.campaigns', 'permission' => 'campaigns.view'],
        'tracking' => ['paths' => ['tracking'], 'page' => 'page.tracking', 'permission' => 'tracking.view'],
        'partners' => ['paths' => ['partners'], 'page' => 'page.partners', 'permission' => 'partners.view'],
        // No route of its own: every figure in money is taken out of the response, the way a role without it is treated.
        'revenue' => ['paths' => [], 'permission' => 'revenue.view'],
    ];

    /** The company in this request when it is this person's, so asking costs no query. */
    public static function of(User $user): ?Tenant
    {
        $current = app(TenantContext::class)->get();

        return $current !== null && $current->id === $user->tenant_id ? $current : $user->tenant;
    }

    /** @return list<string> */
    public static function disabled(?Tenant $tenant): array
    {
        return array_values(array_intersect(array_keys(self::MODULES), (array) ($tenant?->disabled_modules ?? [])));
    }

    /** The module that closes this request for this company, or null. */
    public static function blocking(Request $request, ?Tenant $tenant): ?string
    {
        $path = trim((string) preg_replace('#^api/v1/#', '', $request->path()), '/');

        foreach (self::disabled($tenant) as $module) {
            foreach (self::MODULES[$module]['paths'] as $prefix) {
                $pattern = '#^'.str_replace('\*', '[^/]+', preg_quote($prefix, '#')).'(/|$)#';

                if (preg_match($pattern, $path) === 1) {
                    return $module;
                }
            }
        }

        // The export button on any list is the same module as the exports screen.
        return in_array('exports', self::disabled($tenant), true) && str_ends_with($path, '/export') ? 'exports' : null;
    }

    /** @return list<string> the pages this company has had switched off */
    public static function hiddenPages(?Tenant $tenant): array
    {
        return array_values(array_filter(array_map(fn (string $module) => self::MODULES[$module]['page'] ?? null, self::disabled($tenant))));
    }
}
