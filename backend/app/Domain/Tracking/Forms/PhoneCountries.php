<?php

namespace App\Domain\Tracking\Forms;

use App\Services\Leads\PhoneNormalizer;

/**
 * The countries a phone field offers, and a number in any of the shapes people type it — as one international number or nothing.
 * The page keeps the same list (and the same vectors), so what it accepts is what the server accepts.
 */
final class PhoneCountries
{
    /** ISO 3166 alpha-2 → dial code. The Gulf and the Arab world first, then where buyers abroad usually live. */
    public const DIAL = [
        'SA' => '966', 'AE' => '971', 'KW' => '965', 'QA' => '974', 'BH' => '973', 'OM' => '968', 'EG' => '20', 'JO' => '962',
        'IQ' => '964', 'LB' => '961', 'SY' => '963', 'YE' => '967', 'SD' => '249', 'MA' => '212', 'DZ' => '213', 'TN' => '216',
        'LY' => '218', 'PK' => '92', 'IN' => '91', 'TR' => '90', 'GB' => '44', 'US' => '1', 'DE' => '49', 'FR' => '33',
    ];

    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    public static function has(string $country): bool
    {
        return isset(self::DIAL[$country]);
    }

    /** E.164 (`+966551234567`), or null when this is not a number. */
    public static function e164(string $input, string $country): ?string
    {
        $text = strtr(trim($input), self::DIGITS);
        $digits = preg_replace('/\D/', '', $text) ?? '';
        $international = str_starts_with($text, '+') || str_starts_with($digits, '00');

        if ($digits === '' || preg_match('/[^\d\s\-().+]/', $text)) {
            return null;
        }

        if ($international) {
            $digits = preg_replace('/^00/', '', $digits) ?? $digits;

            return strlen($digits) >= 8 && strlen($digits) <= 15 ? '+'.$digits : null;
        }

        if (! self::has($country)) {
            return null;
        }

        $dial = self::DIAL[$country];

        if ($country === 'SA') {
            return (new PhoneNormalizer)->normalize($digits, $dial);
        }

        $national = ltrim($digits, '0');
        $full = str_starts_with($national, $dial) && strlen($national) - strlen($dial) >= 8 ? $national : $dial.$national;

        return strlen($full) >= 8 && strlen($full) <= 15 ? '+'.$full : null;
    }
}
