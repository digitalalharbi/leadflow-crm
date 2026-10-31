<?php

namespace App\Support;

/**
 * The product's identity on everything the server writes: email subjects and headers, error pages, reports.
 *
 * Arabic: مهتم — حوّل الاهتمام إلى فرصة. English: Mohtam — Turn Interest Into Opportunity. A title is `<what> — مهتم`;
 * the separator is an em dash, never a pipe.
 */
final class Brand
{
    public const NAME_AR = 'مهتم';

    public const NAME_EN = 'Mohtam';

    public const TAGLINE_AR = 'حوّل الاهتمام إلى فرصة';

    public const TAGLINE_EN = 'Turn Interest Into Opportunity';

    public const DOMAIN = 'mohtam.pro';

    /** The one official mailbox: the default sender, and where support, privacy and security reports go. */
    public const EMAIL = 'info@mohtam.pro';

    public const PHONE = '+966532115582';

    public const PHONE_DISPLAY = '+966 53 211 5582';

    public const ADDRESS_AR = 'الرياض، المملكة العربية السعودية';

    public const ADDRESS_EN = 'Riyadh, Saudi Arabia';

    public const SEPARATOR = ' — ';

    /** `التقرير الأسبوعي — مهتم`: a pipe becomes the dash, and the name is added once if the subject does not carry it. */
    public static function subject(string $subject): string
    {
        $clean = trim((string) preg_replace('/\s*\|\s*/u', self::SEPARATOR, $subject));

        if ($clean === '') {
            return self::NAME_AR.self::SEPARATOR.self::TAGLINE_AR;
        }

        return str_contains($clean, self::NAME_AR) || str_contains($clean, self::NAME_EN) ? $clean : $clean.self::SEPARATOR.self::NAME_AR;
    }

    /** `<page> — مهتم`. */
    public static function title(?string $page = null): string
    {
        return $page ? $page.self::SEPARATOR.self::NAME_AR : self::NAME_AR.self::SEPARATOR.self::TAGLINE_AR;
    }

    /** Where the public site lives — the production domain in production, the local app elsewhere. */
    public static function origin(): string
    {
        return rtrim((string) (config('links.public_base') ?: config('integrations.app_url') ?: 'https://'.self::DOMAIN), '/');
    }

    /** The logo emails show: a PNG (mail clients do not draw SVG), from the site's own brand folder. */
    public static function emailLogo(): string
    {
        return self::origin().'/brand/logo-email.png';
    }
}
