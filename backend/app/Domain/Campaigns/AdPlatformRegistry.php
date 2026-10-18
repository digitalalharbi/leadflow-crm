<?php

namespace App\Domain\Campaigns;

use App\Domain\Campaigns\Adapters\MetaAdsAdapter;
use App\Domain\Campaigns\Adapters\PendingAdapter;
use App\Domain\Campaigns\Contracts\AdPlatformAdapter;

/** Platform key → adapter. The one place a new platform is wired in. */
class AdPlatformRegistry
{
    /** @var array<string, AdPlatformAdapter> */
    private array $adapters;

    public function __construct()
    {
        $this->adapters = [
            AdPlatform::Meta->value => new MetaAdsAdapter,
        ];

        foreach (AdPlatform::cases() as $platform) {
            $this->adapters[$platform->value] ??= new PendingAdapter($platform->value);
        }
    }

    public function get(string $platform): AdPlatformAdapter
    {
        return $this->adapters[$platform] ?? new PendingAdapter($platform);
    }

    /** @return list<AdPlatformAdapter> */
    public function all(): array
    {
        return array_values($this->adapters);
    }
}
