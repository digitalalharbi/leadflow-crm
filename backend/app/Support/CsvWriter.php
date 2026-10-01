<?php

namespace App\Support;

/**
 * Writes CSV rows that a spreadsheet will treat as data, not as code.
 *
 * Excel, Google Sheets and LibreOffice evaluate any cell beginning with
 * =, +, - or @ (and treat a leading tab or carriage return as a lead-in to the
 * same). Lead and campaign names in this product arrive from an unauthenticated
 * ingestion endpoint, so an attacker who holds one endpoint secret could plant
 * a formula that runs on the machine of whoever exports the list — the classic
 * CSV injection path, and the reason this class exists rather than a bare
 * fputcsv() call.
 */
class CsvWriter
{
    /** Characters a spreadsheet reads as the start of a formula. */
    private const DANGEROUS = ['=', '+', '-', '@', "\t", "\r"];

    /** @param resource $handle */
    public function __construct(private $handle) {}

    /** @param  resource  $handle */
    public static function open($handle): self
    {
        // Byte-order mark, so Excel renders Arabic rather than mojibake.
        fwrite($handle, "\xEF\xBB\xBF");

        return new self($handle);
    }

    public function writeRow(array $cells): void
    {
        fputcsv($this->handle, array_map([self::class, 'neutralise'], $cells));
    }

    /**
     * Prefixes a formula-shaped value with an apostrophe, which every major
     * spreadsheet reads as "the rest of this cell is text". The value stays
     * legible — `=1+1` becomes `'=1+1`, not a blank.
     */
    public static function neutralise(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = (string) $value;

        if ($value === '') {
            return $value;
        }

        return in_array($value[0], self::DANGEROUS, true) ? "'".$value : $value;
    }
}
