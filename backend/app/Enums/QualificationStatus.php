<?php

namespace App\Enums;

/**
 * Deliberately separate from the pipeline stage. "Qualified" as a stage is a
 * decision the rep makes about where the lead sits in the funnel; this is about
 * how much we actually know about them. A lead can be contacted and still
 * unqualified, and a qualified lead can be lost.
 */
enum QualificationStatus: string
{
    case Unqualified = 'unqualified';
    case PartiallyQualified = 'partially_qualified';
    case Qualified = 'qualified';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
