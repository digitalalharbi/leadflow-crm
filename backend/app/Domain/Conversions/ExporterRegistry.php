<?php

namespace App\Domain\Conversions;

use App\Domain\Conversions\Adapters\GoogleAdsConversionExporter;
use App\Domain\Conversions\Adapters\MetaConversionExporter;
use App\Domain\Conversions\Adapters\SnapchatConversionExporter;
use App\Domain\Conversions\Adapters\TikTokConversionExporter;

class ExporterRegistry
{
    /** @var array<string, class-string<ConversionExporterInterface>> */
    private array $map = [
        'meta' => MetaConversionExporter::class,
        'google_ads' => GoogleAdsConversionExporter::class,
        'tiktok' => TikTokConversionExporter::class,
        'snapchat' => SnapchatConversionExporter::class,
    ];

    public function has(?string $platform): bool
    {
        return $platform !== null && isset($this->map[$platform]);
    }

    public function get(string $platform): ConversionExporterInterface
    {
        return app($this->map[$platform]);
    }

    /** @return array<int,string> platforms that can receive conversions at all */
    public function platforms(): array
    {
        return array_keys($this->map);
    }
}
