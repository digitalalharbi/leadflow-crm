<?php

namespace App\Enums;

enum Platform: string
{
    case Meta = 'meta';
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case TikTok = 'tiktok';
    case Snapchat = 'snapchat';
    case GoogleAds = 'google_ads';
    case LinkedIn = 'linkedin';
    case Website = 'website';
    case LandingPage = 'landing_page';
    case GoogleSheets = 'google_sheets';
    case Webhook = 'webhook';
    case Import = 'import';
    case Manual = 'manual';
    case Other = 'other';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * What to call this source in text the server renders itself.
     *
     * Written out rather than derived from the case name: brand casing is
     * irregular — splitting on capitals turns LinkedIn into "Linked In" and
     * TikTok into "Tik Tok" — and the ones that are not brands have to be
     * Arabic, because the sentence around them is.
     *
     * This does duplicate the interface's `platforms.*` entries. The interface
     * is the source of truth for anything a screen renders; this exists only
     * for notification text, which the server composes and nobody translates
     * afterwards.
     */
    public function label(): string
    {
        return match ($this) {
            self::Meta => 'Meta',
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
            self::TikTok => 'TikTok',
            self::Snapchat => 'Snapchat',
            self::GoogleAds => 'Google Ads',
            self::LinkedIn => 'LinkedIn',
            self::GoogleSheets => 'Google Sheets',
            self::Website => 'الموقع الإلكتروني',
            self::LandingPage => 'صفحة هبوط',
            self::Webhook => 'Webhook',
            self::Import => 'استيراد',
            self::Manual => 'إدخال يدوي',
            self::Other => 'أخرى',
        };
    }

    /** An unknown value is shown as it arrived rather than swallowed. */
    public static function labelFor(?string $value): string
    {
        return $value === null ? '' : (self::tryFrom($value)?->label() ?? $value);
    }
}
