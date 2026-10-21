<?php

namespace App\Domain\Reports;

/**
 * What a shared report can show, and the five starting points.
 *
 * Money is its own set of sections, apart from the volumes: a client or a broker is usually
 * shown how many people came, saw and bought, and not what the ads cost or what the company
 * earned. A section that is off is not hidden by the page — it is not in the response at all.
 */
class ReportTemplates
{
    /** @var list<string> */
    public const SECTIONS = ['kpi_volume', 'kpi_cost', 'kpi_revenue', 'funnel', 'trend', 'platforms', 'campaigns', 'team'];

    /** The money in the report: sections that may not be offered to somebody who should not see them. */
    public const MONEY = ['kpi_cost', 'kpi_revenue'];

    public const TEMPLATES = [
        'executive' => ['kpi_volume', 'kpi_cost', 'kpi_revenue', 'funnel', 'trend', 'platforms'],
        'marketing' => ['kpi_volume', 'kpi_cost', 'funnel', 'trend', 'platforms', 'campaigns'],
        'sales' => ['kpi_volume', 'funnel', 'trend', 'team'],
        'client' => ['kpi_volume', 'funnel', 'trend'],
        'management' => ['kpi_volume', 'kpi_cost', 'kpi_revenue', 'funnel', 'trend', 'platforms', 'campaigns', 'team'],
    ];

    /** @return array<string,bool> every section, on or off, for a template */
    public static function sectionsFor(string $template): array
    {
        $on = self::TEMPLATES[$template] ?? self::TEMPLATES['executive'];

        return collect(self::SECTIONS)->mapWithKeys(fn (string $section) => [$section => in_array($section, $on, true)])->all();
    }

    /** Only known sections, each a boolean; anything missing is off. @param  array<string,mixed>  $given */
    public static function normalise(array $given): array
    {
        return collect(self::SECTIONS)->mapWithKeys(fn (string $section) => [$section => (bool) ($given[$section] ?? false)])->all();
    }
}
