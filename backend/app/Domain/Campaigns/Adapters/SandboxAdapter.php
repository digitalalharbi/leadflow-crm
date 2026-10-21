<?php

namespace App\Domain\Campaigns\Adapters;

use App\Domain\Campaigns\Contracts\AdPlatformAdapter;
use App\Models\AdAccount;
use App\Models\AdConnection;
use Carbon\CarbonInterface;

/**
 * A platform's adapter with a stand-in behind it for the days before it is connected.
 *
 * Reading is the real adapter's, untouched: with no credentials it refuses, as it should. Writing
 * is the real adapter's too whenever the workspace has connected the account; only when it has
 * not, an approved change is *recorded as sent* — the request exactly as it would have gone,
 * flagged `sandbox` — and nothing leaves the building.
 */
final class SandboxAdapter implements AdPlatformAdapter
{
    /** What can be simulated: changes to a campaign that need nothing more than its id. */
    public const SIMULATED = ['pause', 'resume', 'update_budget'];

    public function __construct(private AdPlatformAdapter $inner) {}

    public function platform(): string
    {
        return $this->inner->platform();
    }

    public function isImplemented(): bool
    {
        return true;
    }

    public function isConfigured(?AdConnection $connection): bool
    {
        return true;
    }

    /** Whether the real adapter could carry the change out itself. */
    public function isLive(?AdConnection $connection): bool
    {
        return $this->inner->isImplemented() && $this->inner->isConfigured($connection);
    }

    public function requiredCredentials(): array
    {
        return $this->inner->requiredCredentials();
    }

    public function optionalSettings(): array
    {
        return $this->inner->optionalSettings();
    }

    public function capabilities(): array
    {
        $own = $this->inner->capabilities();

        foreach (self::SIMULATED as $action) {
            $own[$action] = ['supported' => true, 'sandbox' => ! ($own[$action]['supported'] ?? false)] + ($own[$action] ?? []);
        }

        return $own;
    }

    public function actions(): array
    {
        return array_values(array_unique([...$this->inner->actions(), ...self::SIMULATED]));
    }

    public function accounts(AdConnection $connection): array
    {
        return $this->inner->accounts($connection);
    }

    public function structure(AdConnection $connection, AdAccount $account): array
    {
        return $this->inner->structure($connection, $account);
    }

    public function metrics(AdConnection $connection, AdAccount $account, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->inner->metrics($connection, $account, $from, $to);
    }

    public function leadForms(AdConnection $connection, AdAccount $account): ?array
    {
        return $this->inner->leadForms($connection, $account);
    }

    public function execute(?AdConnection $connection, string $action, array $target, array $payload): array
    {
        if ($this->isLive($connection)) {
            return $this->inner->execute($connection, $action, $target, $payload);
        }

        if (! in_array($action, self::SIMULATED, true)) {
            return ['ok' => false, 'request' => [], 'response' => null, 'error' => "{$action} cannot be simulated; connect the {$this->platform()} account."];
        }

        return [
            'ok' => true,
            'request' => ['sandbox' => true, 'platform' => $this->platform(), 'action' => $action, 'target' => $target, 'payload' => $payload],
            'response' => ['sandbox' => true, 'note' => 'Recorded, not sent: this workspace has no connected account for this platform.'],
        ];
    }
}
