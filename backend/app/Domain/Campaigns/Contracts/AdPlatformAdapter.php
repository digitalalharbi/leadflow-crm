<?php

namespace App\Domain\Campaigns\Contracts;

use App\Models\AdAccount;
use App\Models\Integration;
use Carbon\CarbonInterface;

/**
 * One ad platform, behind one door.
 *
 * Everything above this — the sync, the screens, the agents — talks to this
 * interface and never to a platform's SDK, so adding TikTok means writing one
 * class. The API is the foundation; a platform's MCP server may be used where
 * it is official and does the thing, but nothing here requires one.
 *
 * Reading comes first and is all most adapters do. Writing is a separate
 * capability, declared, and reached only through an approved proposal — see
 * the action gateway.
 */
interface AdPlatformAdapter
{
    /** A value of App\Domain\Campaigns\AdPlatform. */
    public function platform(): string;

    /** Whether this adapter has been written at all. False is shown as "coming", never as connected. */
    public function isImplemented(): bool;

    /** Can it reach the platform with what the workspace has supplied? */
    public function isConfigured(?Integration $integration): bool;

    /** @return list<string> the credential keys the workspace must supply */
    public function requiredCredentials(): array;

    /** @return list<string> action keys this adapter can execute, e.g. pause, resume, update_budget */
    public function actions(): array;

    /**
     * @return list<array{external_id: string, name: ?string, currency: ?string, status: string}>
     */
    public function accounts(Integration $integration): array;

    /**
     * @return array{
     *   campaigns: list<array<string, mixed>>,
     *   ad_sets: list<array<string, mixed>>,
     *   ads: list<array<string, mixed>>
     * }
     */
    public function structure(Integration $integration, AdAccount $account): array;

    /**
     * Daily numbers at one level, the finest the platform gives.
     *
     * @return list<array<string, mixed>>
     */
    public function metrics(Integration $integration, AdAccount $account, CarbonInterface $from, CarbonInterface $to): array;

    /**
     * Carry out one approved change. Reached only from the action gateway, never
     * from an agent or a controller.
     *
     * @param  array{level: string, external_id: string}  $target
     * @return array{ok: bool, request: array<string, mixed>, response: array<string, mixed>|null, error?: string}
     */
    public function execute(Integration $integration, string $action, array $target, array $payload): array;
}
