<?php

namespace App\Domain\Reports;

use App\Models\Partner;
use App\Models\ReportShare;

/**
 * What a shared report can show, the starting points, and how a report is laid out.
 *
 * A report is an ordered list of sections. Each line says whether the section is on, who sees it
 * (`public`: anyone with the link; `team`: only the people in the workspace, in the preview) and
 * the period it covers (its own, or the report's). Money is its own set of sections, apart from the
 * volumes: a client or a broker is usually shown how many people came, saw and bought, and not what
 * the ads cost or what the company earned. A section that is off is not hidden by the page — it is
 * not in the response at all.
 */
class ReportTemplates
{
    /** Every section, in the order a report lists them when nobody has said otherwise. @var list<string> */
    public const SECTIONS = [
        'kpi_volume', 'kpi_cost', 'kpi_revenue', 'funnel', 'interested', 'qualification', 'visits', 'reservations', 'trend',
        'platforms', 'campaigns', 'attribution', 'team', 'automation', 'tracking_health', 'insights', 'recommendations', 'statement',
        // The four pages of the management report: marketing, sales, automation and tracking, each a group of figures.
        'exec_marketing', 'exec_sales', 'exec_automation', 'exec_tracking',
        // What a client asks of a real-estate report: where the leads came from and what they cost, what sales did with them,
        // who did it, how good they were, what was lost, per project and unit, through the tracking hub, what came in, and what needs a person.
        'marketing', 'sources', 'sales', 'lead_quality', 'loss', 'projects', 'units', 'tracking_hub', 'revenue', 'attention',
    ];

    /** Templates that need the plan's advanced-templates feature. */
    public const ADVANCED = ['full_funnel', 'project', 'campaign', 'team', 'management'];

    /** Sections that are about the people on the team: a public report shows them only if the owner switched that on. */
    public const TEAM_ONLY = ['team'];

    /** Sections that mean something only in a partner's own report. */
    public const PARTNER_ONLY = ['statement'];

    /** The money in the report: sections that may not be offered to somebody who should not see them. */
    public const MONEY = ['kpi_cost', 'kpi_revenue', 'exec_marketing', 'exec_sales', 'marketing', 'revenue'];

    public const VISIBILITY = ['public', 'team'];

    public const PERIODS = [7, 30, 90, 365];

    /** Each template: the sections it starts with, in the order they appear. */
    public const TEMPLATES = [
        'executive' => ['kpi_volume', 'kpi_cost', 'kpi_revenue', 'funnel', 'trend', 'platforms', 'insights', 'attention'],
        'marketing' => ['kpi_volume', 'kpi_cost', 'funnel', 'trend', 'platforms', 'marketing', 'campaigns', 'attribution', 'tracking_hub', 'insights'],
        'sales' => ['kpi_volume', 'funnel', 'sales', 'interested', 'visits', 'reservations', 'trend', 'team', 'loss', 'attention'],
        // The funnel from end to end: how many, from where, how far each got, and where the rest were lost.
        'full_funnel' => ['kpi_volume', 'funnel', 'trend', 'sources', 'sales', 'visits', 'reservations', 'loss', 'insights', 'attention'],
        // One project, or a portfolio: its leads, its units, what it earned.
        'project' => ['kpi_volume', 'kpi_revenue', 'funnel', 'trend', 'projects', 'units', 'sources', 'visits', 'reservations', 'revenue', 'insights'],
        // Campaigns against each other, platform-reported and Mohtam-attributed side by side.
        'campaign' => ['kpi_volume', 'kpi_cost', 'funnel', 'trend', 'marketing', 'platforms', 'campaigns', 'attribution', 'tracking_hub', 'insights', 'attention'],
        // The sales team: how fast, how many, how well.
        'team' => ['kpi_volume', 'funnel', 'sales', 'team', 'visits', 'reservations', 'insights', 'attention'],
        'client' => ['kpi_volume', 'funnel', 'trend'],
        // For an investor: what came in, how far it got, what it returned, and what to do about it.
        'investor' => ['kpi_volume', 'kpi_revenue', 'funnel', 'reservations', 'trend', 'projects', 'revenue', 'insights', 'recommendations'],
        // For a partner: their own leads down the funnel, and nothing about the company's money or other people.
        'partner' => ['kpi_volume', 'funnel', 'trend'],
        // A partner's statement: what they brought, what they are owed and have been paid.
        'statement' => ['kpi_volume', 'statement', 'funnel', 'trend'],
        // The management report: marketing, sales, automation and tracking in one place, then the funnel, the trend and what to do.
        'executive_management' => ['exec_marketing', 'exec_sales', 'funnel', 'exec_automation', 'exec_tracking', 'trend', 'insights', 'recommendations'],
        'management' => self::SECTIONS,
    ];

    /** What a partner's report may never carry, whatever was switched on: the company's own cost and other people's data. */
    public const NOT_FOR_PARTNERS = ['kpi_cost', 'marketing', 'sources', 'sales', 'lead_quality', 'loss', 'projects', 'units', 'tracking_hub', 'revenue', 'attention', 'platforms', 'campaigns', 'attribution', 'team', 'automation', 'tracking_health', 'insights', 'recommendations', 'exec_marketing', 'exec_sales', 'exec_automation', 'exec_tracking'];

    /** @return list<string> the template's sections in order, then the rest (off) */
    private static function order(string $template): array
    {
        $on = self::TEMPLATES[$template] ?? self::TEMPLATES['executive'];

        return array_merge($on, array_values(array_diff(self::SECTIONS, $on)));
    }

    /** @return array<string,bool> every section, on or off, for a template */
    public static function sectionsFor(string $template): array
    {
        $on = self::TEMPLATES[$template] ?? self::TEMPLATES['executive'];

        return collect(self::SECTIONS)->mapWithKeys(fn (string $section) => [$section => in_array($section, $on, true)])->all();
    }

    /** @return list<array{key:string,on:bool,visible_to:string,days:?int}> a template's layout: its own order, the rest off */
    public static function layoutFor(string $template): array
    {
        $on = self::TEMPLATES[$template] ?? self::TEMPLATES['executive'];

        return array_map(fn (string $key) => ['key' => $key, 'on' => in_array($key, $on, true), 'visible_to' => 'public', 'days' => null], self::order($template));
    }

    /**
     * What a partner's report really has: it loses the company's cost and everything about other
     * campaigns, platforms and people, and loses revenue unless the partner is allowed it.
     *
     * @param  array<string,bool>  $sections
     * @return array<string,bool>
     */
    public static function effective(array $sections, ?Partner $partner): array
    {
        if ($partner === null) {
            return $sections;
        }

        foreach (self::NOT_FOR_PARTNERS as $hidden) {
            $sections[$hidden] = false;
        }

        $sections['kpi_revenue'] = ($sections['kpi_revenue'] ?? false) && $partner->show_revenue;

        return $sections;
    }

    /** Only known sections, each a boolean; anything missing is off. @param  array<string,mixed>  $given */
    public static function normalise(array $given): array
    {
        return collect(self::SECTIONS)->mapWithKeys(fn (string $section) => [$section => (bool) ($given[$section] ?? false)])->all();
    }

    /**
     * Cleans a layout somebody sent: known sections only, each once, in the order given, with every
     * section that was left out added at the end (off). Anything unreadable becomes the default.
     *
     * @param  list<array<string,mixed>>  $given
     * @return list<array{key:string,on:bool,visible_to:string,days:?int}>
     */
    public static function normaliseLayout(array $given, string $template): array
    {
        $seen = [];
        $out = [];

        foreach ($given as $row) {
            $key = $row['key'] ?? null;

            if (! in_array($key, self::SECTIONS, true) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $days = $row['days'] ?? null;
            $out[] = [
                'key' => $key,
                'on' => (bool) ($row['on'] ?? false),
                'visible_to' => in_array($row['visible_to'] ?? 'public', self::VISIBILITY, true) ? $row['visible_to'] ?? 'public' : 'public',
                'days' => in_array($days, self::PERIODS, true) ? (int) $days : null,
            ];
        }

        foreach (self::order($template) as $key) {
            if (! isset($seen[$key])) {
                $out[] = ['key' => $key, 'on' => false, 'visible_to' => 'public', 'days' => null];
            }
        }

        return $out;
    }

    /**
     * A share's layout: the one saved, or — for a share made with a plain on/off map — the template's
     * order with that map's switches applied.
     *
     * @return list<array{key:string,on:bool,visible_to:string,days:?int}>
     */
    public static function layoutOf(ReportShare $share): array
    {
        if (! empty($share->layout)) {
            return self::normaliseLayout($share->layout, $share->template);
        }

        $map = $share->sections ?? [];

        return array_map(fn (array $row) => ['on' => (bool) ($map[$row['key']] ?? false)] + $row, self::layoutFor($share->template));
    }

    /**
     * The sections a viewer is given, in order: on, permitted for a partner, and visible to the
     * audience. `team` sees everything that is on; `public` does not see what is for the team only.
     *
     * @return list<array{key:string,days:?int}>
     */
    public static function plan(ReportShare $share, string $audience = 'public'): array
    {
        $partner = $share->partner_id ? $share->partner : null;
        $allowed = self::effective(array_column(array_map(fn ($r) => ['k' => $r['key'], 'v' => $r['on']], self::layoutOf($share)), 'v', 'k'), $partner);

        $exposeTeam = (bool) ($share->options['expose_team'] ?? false);

        return collect(self::layoutOf($share))
            ->filter(fn (array $row) => ($allowed[$row['key']] ?? false) && ($audience === 'team' || $row['visible_to'] === 'public') && ($partner !== null || ! in_array($row['key'], self::PARTNER_ONLY, true))
                && ($audience === 'team' || $exposeTeam || ! in_array($row['key'], self::TEAM_ONLY, true)))
            ->map(fn (array $row) => ['key' => $row['key'], 'days' => $row['days']])
            ->values()->all();
    }
}
