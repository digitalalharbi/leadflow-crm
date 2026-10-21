<?php

namespace App\Domain\Campaigns;

use App\Domain\Campaigns\Adapters\GoogleAdsAdapter;
use App\Domain\Campaigns\Adapters\LinkedInAdsAdapter;
use App\Domain\Campaigns\Adapters\MetaAdsAdapter;
use App\Domain\Campaigns\Adapters\PendingAdapter;
use App\Domain\Campaigns\Adapters\SandboxAdapter;
use App\Domain\Campaigns\Adapters\SnapchatAdsAdapter;
use App\Domain\Campaigns\Adapters\TikTokAdsAdapter;
use App\Domain\Campaigns\Adapters\XAdsAdapter;
use App\Domain\Campaigns\Contracts\AdPlatformAdapter;
use App\Support\Deployment;

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
        return $this->made[$platform] ??= $this->make($platform);
    }

    /** Where sandbox is on (never in production), the real adapter gets a stand-in behind it for unconnected accounts. */
    public function sandboxed(): bool
    {
        return (bool) config('ads.sandbox') && ! Deployment::isProduction();
    }

    private function make(string $platform): AdPlatformAdapter
    {
        $adapter = isset(self::ADAPTERS[$platform]) ? app(self::ADAPTERS[$platform]) : new PendingAdapter($platform);

        return $this->sandboxed() ? new SandboxAdapter($adapter) : $adapter;
    }

    /** @return list<AdPlatformAdapter> */
    public function all(): array
    {
        return array_map(fn (AdPlatform $p) => $this->get($p->value), AdPlatform::cases());
    }
}
