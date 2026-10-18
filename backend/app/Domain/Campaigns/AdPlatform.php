<?php

namespace App\Domain\Campaigns;

/**
 * The six places an ad can run.
 *
 * Distinct from the lead `Platform` enum on purpose: a lead says where it came
 * from ("facebook", "instagram", "website"), a campaign says where it runs, and
 * Facebook and Instagram leads both belong to a Meta campaign.
 */
enum AdPlatform: string
{
    case Meta = 'meta';
    case TikTok = 'tiktok';
    case GoogleAds = 'google_ads';
    case Snapchat = 'snapchat';
    case X = 'x';
    case LinkedIn = 'linkedin';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** The ad platform a lead's `platform` string belongs to, if it came from one. */
    public static function fromLeadPlatform(?string $platform): ?self
    {
        return match (strtolower(trim((string) $platform))) {
            'meta', 'facebook', 'instagram' => self::Meta,
            'tiktok' => self::TikTok,
            'google_ads', 'google' => self::GoogleAds,
            'snapchat', 'snap' => self::Snapchat,
            'x', 'twitter' => self::X,
            'linkedin' => self::LinkedIn,
            default => null,
        };
    }
}
