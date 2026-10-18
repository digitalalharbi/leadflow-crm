<?php

namespace App\Domain\Campaigns\Adapters;

use App\Domain\Campaigns\Contracts\AdPlatformAdapter;
use App\Models\AdAccount;
use App\Models\Integration;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * A platform whose adapter has not been written yet.
 *
 * It exists so the platform is a real, listed thing with a truthful state —
 * "not built" — rather than an absent row or, worse, a green tick. Every read
 * refuses; nothing is invented. Replacing one of these with a real adapter is
 * adding a class and one line in the registry.
 */
class PendingAdapter implements AdPlatformAdapter
{
    public function __construct(private string $platform) {}

    public function platform(): string
    {
        return $this->platform;
    }

    public function isImplemented(): bool
    {
        return false;
    }

    public function isConfigured(?Integration $integration): bool
    {
        return false;
    }

    public function requiredCredentials(): array
    {
        return [];
    }

    public function actions(): array
    {
        return [];
    }

    public function accounts(Integration $integration): array
    {
        throw $this->pending();
    }

    public function structure(Integration $integration, AdAccount $account): array
    {
        throw $this->pending();
    }

    public function metrics(Integration $integration, AdAccount $account, CarbonInterface $from, CarbonInterface $to): array
    {
        throw $this->pending();
    }

    private function pending(): RuntimeException
    {
        return new RuntimeException("The {$this->platform} adapter is not built yet.");
    }
}
