<?php

namespace App\Domain\Campaigns\Contracts;

use App\Models\AdAccount;
use App\Models\AdConnection;
use Carbon\CarbonInterface;

/**
 * One ad platform, behind one door.
 *
 * Everything above this — the sync, the screens, the agents — talks to this
 * interface and never to a platform's API, so adding a platform means writing
 * one class. The API is the foundation; a platform's MCP server may be used
 * where it is official and does the thing, but nothing here requires one.
 *
 * Reading comes first. Writing is a separate, declared capability, reached only
 * through an approved proposal — see the action gateway. Every action the
 * product knows is listed in capabilities() as supported or not, with the
 * reason, so the screen can say "this platform does not allow that" instead of
 * offering a button that fails.
 */
interface AdPlatformAdapter
{
    /** A value of App\Domain\Campaigns\AdPlatform. */
    public function platform(): string;

    /** Whether this adapter has been written at all. */
    public function isImplemented(): bool;

    /** Can it reach the platform with what the workspace has supplied? */
    public function isConfigured(?AdConnection $connection): bool;

    /** @return list<string> the credential keys the workspace must supply */
    public function requiredCredentials(): array;

    /** @return list<string> optional settings (account ids, API version, …) */
    public function optionalSettings(): array;

    /** @return array<string, array{supported: bool, reason?: string}> every action, supported or not */
    public function capabilities(): array;

    /** @return list<string> the supported action keys */
    public function actions(): array;

    /** @return list<array{external_id: string, name: ?string, currency: ?string, status: string}> */
    public function accounts(AdConnection $connection): array;

    /**
     * @return array{campaigns: list<array<string, mixed>>, ad_sets: list<array<string, mixed>>, ads: list<array<string, mixed>>}
     */
    public function structure(AdConnection $connection, AdAccount $account): array;

    /** Daily numbers at one level, the finest the platform gives. @return list<array<string, mixed>> */
    public function metrics(AdConnection $connection, AdAccount $account, CarbonInterface $from, CarbonInterface $to): array;

    /** @return list<array{external_id: string, name: string, status: ?string}>|null null when the platform has no lead forms to list */
    public function leadForms(AdConnection $connection, AdAccount $account): ?array;

    /**
     * Carry out one approved change. Reached only from the action gateway.
     *
     * @param  array{level: string, external_id: ?string, account_external_id: ?string, parent_external_id?: ?string}  $target
     * @return array{ok: bool, request: array<string, mixed>, response: array<string, mixed>|null, error?: ?string, created_external_id?: ?string}
     */
    public function execute(AdConnection $connection, string $action, array $target, array $payload): array;
}
