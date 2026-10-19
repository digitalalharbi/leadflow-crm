<?php

namespace App\Domain\Campaigns;

use App\Domain\Campaigns\Adapters\GoogleAdsAdapter;
use App\Domain\Campaigns\Adapters\LinkedInAdsAdapter;
use App\Domain\Campaigns\Adapters\MetaAdsAdapter;
use App\Domain\Campaigns\Adapters\PendingAdapter;
use App\Domain\Campaigns\Adapters\SnapchatAdsAdapter;
use App\Domain\Campaigns\Adapters\TikTokAdsAdapter;
use App\Domain\Campaigns\Adapters\XAdsAdapter;
use App\Domain\Campaigns\Contracts\AdPlatformAdapter;

/** Platform key → adapter. The one place a platform is wired in. */
class AdPlatformRegistry
{
    private const ADAPTERS = [
        'meta' => MetaAdsAdapter::class,
        'tiktok' => TikTokAdsAdapter::class,
        'google_ads' => GoogleAdsAdapter::class,
        'snapchat' => SnapchatAdsAdapter::class,
        'x' => XAdsAdapter::class,
        'linkedin' => LinkedInAdsAdapter::class,
    ];

    /** @var array<string, AdPlatformAdapter> */
    private array $made = [];

    public function get(string $platform): AdPlatformAdapter
    {
        return $this->made[$platform] ??= isset(self::ADAPTERS[$platform]) ? app(self::ADAPTERS[$platform]) : new PendingAdapter($platform);
    }

    /** @return list<AdPlatformAdapter> */
    public function all(): array
    {
        return array_map(fn (AdPlatform $p) => $this->get($p->value), AdPlatform::cases());
    }
}
