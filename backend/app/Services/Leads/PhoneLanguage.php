<?php

namespace App\Services\Leads;

/**
 * Which language a lead is likely to want, read off their phone number.
 *
 * Nothing in a lead form says what language the person speaks. The name is
 * typed by whoever filled the form — often in Latin letters by an Arabic
 * speaker, often in Arabic by somebody whose Arabic stops at their own name —
 * and the form's own language says what page the ad ran on, not who clicked it.
 *
 * The number is the one field that comes from somewhere real: a +92 number was
 * issued to somebody in Pakistan, and a rep who speaks Urdu will have a better
 * conversation with them than one who does not. That is a likelihood and not a
 * fact, which is why every use of it here is a preference that steps aside
 * rather than a rule that blocks.
 *
 * Deliberately not a language *detector*. It answers one question — "if we had
 * to guess from the number alone, who should call them?" — and answers null
 * wherever the honest answer is "the country does not tell you", so the caller
 * falls through instead of acting on a coin toss.
 */
class PhoneLanguage
{
    /**
     * Calling code to the language somebody dialling from there most likely
     * wants to be sold to in. The longest matching prefix wins, so +97 is never
     * read off a +971 number.
     *
     * Countries whose calling code genuinely does not pick one language are
     * absent on purpose rather than guessed at — Switzerland, Belgium, Canada
     * inside the +1 block — and absent is the same answer as unknown: no
     * opinion.
     */
    private const BY_CALLING_CODE = [
        // Arabic, which for a Saudi developer is most of the list.
        '966' => 'ar',  // السعودية
        '971' => 'ar',  // الإمارات
        '973' => 'ar',  // البحرين
        '974' => 'ar',  // قطر
        '965' => 'ar',  // الكويت
        '968' => 'ar',  // عمان
        '962' => 'ar',  // الأردن
        '961' => 'ar',  // لبنان
        '963' => 'ar',  // سوريا
        '964' => 'ar',  // العراق
        '967' => 'ar',  // اليمن
        '970' => 'ar',  // فلسطين
        '20' => 'ar',   // مصر
        '249' => 'ar',  // السودان
        '218' => 'ar',  // ليبيا
        '216' => 'ar',  // تونس
        '213' => 'ar',  // الجزائر
        '212' => 'ar',  // المغرب
        '222' => 'ar',  // موريتانيا

        /*
         * English, including the countries where it is the working language
         * rather than the mother tongue. India and the Philippines are here for
         * that reason: neither has one language a number can pick out, and
         * English is the one a rep can be expected to share — which is the
         * question being asked, not which language is spoken at home.
         */
        '1' => 'en',    // United States, Canada, the Caribbean
        '44' => 'en',   // United Kingdom
        '353' => 'en',  // Ireland
        '61' => 'en',   // Australia
        '64' => 'en',   // New Zealand
        '27' => 'en',   // South Africa
        '234' => 'en',  // Nigeria
        '254' => 'en',  // Kenya
        '233' => 'en',  // Ghana
        '91' => 'en',   // India
        '63' => 'en',   // Philippines
        '65' => 'en',   // Singapore
        '60' => 'en',   // Malaysia — English is the second working language

        // The rest of the expatriate and investor map, in rough order of how
        // often a Gulf developer sees it.
        '92' => 'ur',   // Pakistan
        '880' => 'bn',  // Bangladesh
        '90' => 'tr',   // Türkiye
        '98' => 'fa',   // Iran
        '93' => 'fa',   // Afghanistan — Dari, which `fa` covers
        '7' => 'ru',    // Russia, Kazakhstan
        '380' => 'uk',  // Ukraine
        '86' => 'zh',   // China
        '62' => 'id',   // Indonesia
        '66' => 'th',   // Thailand
        '84' => 'vi',   // Vietnam
        '81' => 'ja',   // Japan
        '82' => 'ko',   // South Korea
        '977' => 'ne',  // Nepal
        '251' => 'am',  // Ethiopia
        '33' => 'fr',   // France
        '49' => 'de',   // Germany
        '43' => 'de',   // Austria
        '34' => 'es',   // Spain
        '52' => 'es',   // Mexico
        '54' => 'es',   // Argentina
        '57' => 'es',   // Colombia
        '39' => 'it',   // Italy
        '31' => 'nl',   // Netherlands
        '351' => 'pt',  // Portugal
        '55' => 'pt',   // Brazil
        '48' => 'pl',   // Poland
        '46' => 'sv',   // Sweden
        '47' => 'nb',   // Norway
        '45' => 'da',   // Denmark
        '358' => 'fi',  // Finland
    ];

    /**
     * @param  string|null  $e164  as `phone_normalized` stores it: +<code><number>
     * @return string|null an ISO 639-1 code, or null when the number says nothing
     */
    public function for(?string $e164): ?string
    {
        if ($e164 === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $e164) ?? '';

        /*
         * A number that was never normalised cannot be read: without the
         * country code, `0551234567` and `551234567` are a Riyadh mobile, a
         * Turkish one and half a dozen others at the same time. Guessing from
         * the workspace's default country would make every local number agree
         * with the default and tell us nothing we did not already assume.
         */
        if (! str_starts_with((string) $e164, '+') || strlen($digits) < 8) {
            return null;
        }

        return $this->lookUp($digits);
    }

    /**
     * The same reading, from a calling code on its own.
     *
     * A workspace's own working language comes out of this: the default country
     * code in its settings is read exactly the way a customer's number is, so
     * the two can never disagree about what `966` means.
     */
    public function forCallingCode(?string $code): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $code) ?? '';

        return $digits === '' ? null : $this->lookUp($digits);
    }

    /**
     * Longest prefix first, so +966 is not read as +96 and +1 only matches what
     * nothing longer claimed.
     */
    private function lookUp(string $digits): ?string
    {
        foreach ([3, 2, 1] as $length) {
            $language = self::BY_CALLING_CODE[substr($digits, 0, $length)] ?? null;

            if ($language !== null) {
                return $language;
            }
        }

        return null;
    }

    /** Every language this can produce, for a settings screen to offer. */
    public static function known(): array
    {
        return array_values(array_unique(array_values(self::BY_CALLING_CODE)));
    }
}
