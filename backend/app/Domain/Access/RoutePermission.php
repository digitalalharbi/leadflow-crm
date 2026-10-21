<?php

namespace App\Domain\Access;

use Illuminate\Http\Request;

/**
 * Which permission a request needs, worked out from what it is: the area (the first part of the
 * path) and what it does to it (the verb, or a word at the end of the path).
 *
 * Reading the route rather than annotating every one keeps the table in a single place and means
 * a new route in a known area is governed the day it is added. A route in no known area returns
 * null, and for the specialised roles null means refused.
 */
class RoutePermission
{
    /** Paths every signed-in person may use, whatever their role. */
    private const OPEN = ['auth', 'notifications', 'branding', 'whatsapp/capability', 'email/capability', 'roles'];

    /** First path segment → area. */
    private const AREAS = [
        'dashboard' => 'dashboard', 'audit' => 'audit', 'insights' => 'analytics', 'anomalies' => 'analytics',
        'leads' => 'leads', 'work' => 'leads', 'pipeline' => 'leads', 'follow-ups' => 'leads', 'qualification' => 'leads',
        'imports' => 'leads', 'search' => 'leads', 'data-quality' => 'leads', 'message-templates' => 'leads', 'saved-views' => 'leads',
        'appointments' => 'visits', 'reservations' => 'reservations', 'properties' => 'properties',
        'projects' => 'projects', 'companies' => 'companies',
        'campaigns' => 'campaigns', 'campaign-spend' => 'campaigns', 'ad-connections' => 'campaigns', 'creatives' => 'campaigns',
        'creative-drafts' => 'campaigns', 'action-proposals' => 'campaigns', 'agents' => 'campaigns', 'agent-reports' => 'campaigns',
        'client-rules' => 'campaigns', 'conversions' => 'campaigns',
        'tracking' => 'tracking', 'partners' => 'partners', 'reports' => 'analytics', 'report-shares' => 'reports_share',
        'automation' => 'automation', 'scheduled-actions' => 'automation',
        'integrations' => 'integrations', 'webhook-endpoints' => 'integrations', 'webhook-logs' => 'integrations',
        'users' => 'users', 'access' => 'users', 'settings' => 'settings', 'readiness' => 'settings', 'onboarding' => 'settings', 'demo' => 'settings',
    ];

    /** @return string|null "resource.action", or null for a path no area claims; '' for an open one */
    public static function for(Request $request): ?string
    {
        $path = trim(preg_replace('#^api/v1/#', '', $request->path()), '/');

        foreach (self::OPEN as $open) {
            if ($path === $open || str_starts_with($path, $open.'/')) {
                return '';
            }
        }

        $segments = explode('/', $path);
        $area = self::AREAS[$segments[0]] ?? null;
        $last = end($segments);

        // A project's own sub-resources belong to the thing they configure.
        if ($segments[0] === 'projects' && isset($segments[2])) {
            $area = match ($segments[2]) {
                'thresholds', 'scoring', 'automation-pack', 'questions' => 'automation',
                'properties' => 'properties',
                default => 'projects',
            };
        }

        // The pickers that fill a dropdown are part of working a lead, not of managing users.
        if ($path === 'users/assignable') {
            $area = 'leads';
        }

        if ($segments[0] === 'leads' && ($segments[1] ?? null) === 'saved-views') {
            $area = 'leads';
        }

        // How platforms are doing and who gets the credit are analyses, not links: they take the analytics permission.
        if ($segments[0] === 'tracking' && in_array($segments[1] ?? '', ['health', 'attribution'], true)) {
            $area = 'analytics';
        }

        if ($area === null) {
            return null;
        }

        $method = $request->method();

        // The work queue is a tool for working leads: reading it takes the same permission as acting in it.
        if ($segments[0] === 'work') {
            return 'leads.execute';
        }

        $action = match (true) {
            $method === 'GET' && $last === 'export' => 'export',
            $method === 'GET' || $method === 'HEAD' => 'view',
            $method === 'DELETE' => 'disable',
            in_array($last, ['approve', 'reject'], true) => 'approve',
            $area === 'reports_share' && in_array($last, ['revoke', 'regenerate'], true) => 'share',
            // POST /leads makes one; POST on anything under a lead works it.
            $method === 'POST' && $area === 'leads' && ($segments[0] === 'leads' && count($segments) === 1) => 'create',
            $method === 'POST' && in_array($last, ['sync', 'run', 'generate', 'test', 'commission', 'simulate', 'process', 'convert', 'confirm', 'cancel', 'transition', 'outcome', 'assign', 'status', 'temperature', 'interest', 'contact', 'notes', 'messages', 'emails', 'merge', 'pause', 'resume', 'stop', 'reschedule', 'share', 'send', 'duplicate', 'operate'], true) => $area === 'leads' ? 'execute' : (in_array($area, ['visits', 'reservations'], true) ? 'edit' : 'execute'),
            $method === 'POST' && $area === 'leads' => 'execute',
            $method === 'POST' => 'create',
            default => 'edit',
        };

        return "{$area}.{$action}";
    }
}
