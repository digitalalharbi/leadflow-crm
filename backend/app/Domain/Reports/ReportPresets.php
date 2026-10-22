<?php

namespace App\Domain\Reports;

/**
 * The reports a workspace starts from, ready-made: who it is for, what it carries, how far back it
 * looks and whether it puts the period before beside every figure.
 *
 * A preset is a template (the sections and their order, see ReportTemplates) with the defaults that
 * suit its reader. Choosing one fills the form in; everything on the form can still be changed, and
 * nothing here is a rule — the rules (who may add which section, what a partner never sees) are
 * ReportTemplates' and the element registry's.
 */
class ReportPresets
{
    /** @var array<string,array{template:string,days:int,compare:bool,refresh_seconds:int,audience:string,needs_partner:bool}> */
    public const PRESETS = [
        'executive_management' => ['template' => 'executive_management', 'days' => 30, 'compare' => true, 'refresh_seconds' => 300, 'audience' => 'management', 'needs_partner' => false],
        'marketing' => ['template' => 'marketing', 'days' => 30, 'compare' => true, 'refresh_seconds' => 300, 'audience' => 'marketing', 'needs_partner' => false],
        'sales' => ['template' => 'sales', 'days' => 30, 'compare' => true, 'refresh_seconds' => 60, 'audience' => 'sales', 'needs_partner' => false],
        'client' => ['template' => 'client', 'days' => 30, 'compare' => false, 'refresh_seconds' => 900, 'audience' => 'client', 'needs_partner' => false],
        'investor' => ['template' => 'investor', 'days' => 90, 'compare' => true, 'refresh_seconds' => 900, 'audience' => 'investor', 'needs_partner' => false],
        'partner' => ['template' => 'partner', 'days' => 30, 'compare' => false, 'refresh_seconds' => 900, 'audience' => 'partner', 'needs_partner' => true],
    ];

    /** @return array<string,mixed>|null */
    public static function find(string $key): ?array
    {
        return self::PRESETS[$key] ?? null;
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return array_map(fn (string $key, array $p) => ['key' => $key] + $p + [
            'sections' => ReportTemplates::sectionsFor($p['template']),
            'layout' => ReportTemplates::layoutFor($p['template']),
        ], array_keys(self::PRESETS), self::PRESETS);
    }
}
