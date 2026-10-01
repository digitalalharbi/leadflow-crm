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
}
