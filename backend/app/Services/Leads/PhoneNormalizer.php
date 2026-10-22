<?php

namespace App\Services\Leads;

/**
 * Normalises phone numbers to E.164 so duplicate detection works across sources.
 *
 * Saudi numbers arrive in every imaginable shape — 05XXXXXXXX from a landing page,
 * 5XXXXXXXX from a spreadsheet, 9665XXXXXXXX from Meta, +9665XXXXXXXX from a form.
 * All four must collapse to the same key.
 */
class PhoneNormalizer
{
    /** Arabic-Indic and Eastern Arabic-Indic digits seen in pasted/imported data. */
    private const DIGIT_MAP = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /**
     * @return string|null E.164 (e.g. +966512345678), or null when unusable.
     */
    public function normalize(?string $raw, string $defaultCountryCode = '966'): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = $this->toAsciiDigits($raw);

        if ($digits === '') {
            return null;
        }

        $defaultCountryCode = ltrim($defaultCountryCode, '+');

        // 00966... -> 966...
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if ($defaultCountryCode === '966') {
            $digits = $this->normalizeSaudi($digits);
        } else {
            $digits = $this->normalizeGeneric($digits, $defaultCountryCode);
        }

        if ($digits === null) {
            return null;
        }

        // Guard against junk like "1" or a 25-digit paste.
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return '+'.$digits;
    }

    /** Saudi mobile numbers are 9 digits after the country code and start with 5. */
    private function normalizeSaudi(string $digits): ?string
    {
        // +966 5XXXXXXXX  (already full international)
        if (str_starts_with($digits, '966')) {
            $national = substr($digits, 3);
            $national = ltrim($national, '0');

            return $this->isSaudiMobile($national) ? '966'.$national : '966'.$national;
        }

        // 05XXXXXXXX (national trunk prefix)
        if (str_starts_with($digits, '05') && strlen($digits) === 10) {
            return '966'.substr($digits, 1);
        }

        // 5XXXXXXXX (bare mobile)
        if (str_starts_with($digits, '5') && strlen($digits) === 9) {
            return '966'.$digits;
        }

        // Landline 01XXXXXXXX etc.
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '966'.substr($digits, 1);
        }

        // Anything else already carrying a different country code is left intact.
        return $digits;
    }

    private function normalizeGeneric(string $digits, string $countryCode): ?string
    {
        if (str_starts_with($digits, $countryCode)) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            return $countryCode.ltrim($digits, '0');
        }

        // Plausible local number without a country code.
        if (strlen($digits) <= 10) {
            return $countryCode.$digits;
        }

        return $digits;
    }

    private function isSaudiMobile(string $national): bool
    {
        return strlen($national) === 9 && str_starts_with($national, '5');
    }

    /** Strip formatting, convert Arabic-Indic digits, keep only 0-9. */
    private function toAsciiDigits(string $raw): string
    {
        $raw = strtr($raw, self::DIGIT_MAP);

        return preg_replace('/\D+/', '', $raw) ?? '';
    }

    /** ASCII digits in a string, converting Arabic-Indic numerals on the way. */
    public function digits(string $raw): string
    {
        return $this->toAsciiDigits($raw);
    }

    /** Digits only, for wa.me links. */
    public function toWhatsApp(?string $normalized): ?string
    {
        return $normalized ? ltrim($normalized, '+') : null;
    }
}
