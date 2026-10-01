<?php

namespace App\Enums;

enum IntegrationProvider: string
{
    case GenericWebhook = 'generic_webhook';
    case GoogleSheets = 'google_sheets';
    case Meta = 'meta';
    case TikTok = 'tiktok';
    case GoogleAds = 'google_ads';
    case LinkedIn = 'linkedin';
    case Snapchat = 'snapchat';
    case CsvImport = 'csv_import';
    case Manual = 'manual';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function defaultPlatform(): Platform
    {
        return match ($this) {
            self::GenericWebhook => Platform::Webhook,
            self::GoogleSheets => Platform::GoogleSheets,
            self::Meta => Platform::Meta,
            self::TikTok => Platform::TikTok,
            self::GoogleAds => Platform::GoogleAds,
            self::LinkedIn => Platform::LinkedIn,
            self::Snapchat => Platform::Snapchat,
            self::CsvImport => Platform::Import,
            self::Manual => Platform::Manual,
        };
    }
}
