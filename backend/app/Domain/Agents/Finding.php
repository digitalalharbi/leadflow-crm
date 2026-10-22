<?php

namespace App\Domain\Agents;

use App\Models\Campaign;

/** One thing an agent noticed about one campaign, with the figures behind it. */
final class Finding
{
    /** @param  array<string, mixed>  $figures */
    public function __construct(
        public readonly string $key,
        public readonly string $severity, // bad | warn | good
        public readonly Campaign $campaign,
        public readonly array $figures = [],
    ) {}

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'severity' => $this->severity,
            'campaign' => ['id' => $this->campaign->uuid, 'name' => $this->campaign->name, 'platform' => $this->campaign->platform],
            'figures' => $this->figures,
        ];
    }
}
