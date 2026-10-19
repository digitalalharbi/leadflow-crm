<?php

namespace App\Domain\Campaigns\Adapters;

use App\Domain\Actions\ActionPolicy;
use App\Domain\Campaigns\Contracts\AdPlatformAdapter;
use App\Models\AdAccount;
use App\Models\AdConnection;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * A platform whose adapter does not exist. Every read refuses, every action is
 * unsupported, and nothing is invented.
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

    public function isConfigured(?AdConnection $connection): bool
    {
        return false;
    }

    public function requiredCredentials(): array
    {
        return [];
    }

    public function optionalSettings(): array
    {
        return [];
    }

    public function capabilities(): array
    {
        return array_fill_keys(ActionPolicy::ACTIONS, ['supported' => false, 'reason' => 'platform_not_built']);
    }

    public function actions(): array
    {
        return [];
    }

    public function accounts(AdConnection $connection): array
    {
        throw $this->pending();
    }

    public function structure(AdConnection $connection, AdAccount $account): array
    {
        throw $this->pending();
    }

    public function metrics(AdConnection $connection, AdAccount $account, CarbonInterface $from, CarbonInterface $to): array
    {
        throw $this->pending();
    }

    public function leadForms(AdConnection $connection, AdAccount $account): ?array
    {
        return null;
    }

    public function execute(AdConnection $connection, string $action, array $target, array $payload): array
    {
        return ['ok' => false, 'request' => [], 'response' => null, 'error' => "The {$this->platform} adapter is not built."];
    }

    private function pending(): RuntimeException
    {
        return new RuntimeException("The {$this->platform} adapter is not built.");
    }
}
