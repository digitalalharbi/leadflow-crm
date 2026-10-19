<?php

namespace App\Domain\Campaigns;

use App\Domain\Campaigns\Contracts\AdPlatformAdapter;
use App\Domain\Integrations\IntegrationState;
use App\Models\AdConnection;

/**
 * How far each ad platform has actually been proven, on the same six-rung
 * ladder as every other integration.
 *
 *   not_configured       no credentials (or no adapter at all)
 *   code_verified        credentials entered; the adapter's code is covered by
 *                        tests against the platform's documented responses
 *   sandbox_verified     a real sync succeeded against a sandbox/test account
 *   ready                a connection test reached the live platform
 *   live_verified        a live sync brought back real accounts
 *   production_verified  …in production, within the last day, with no error
 *
 * Only the platform answering moves a rung. Tests run against a separate
 * database with faked HTTP, so nothing a test does can ever appear here.
 */
class PlatformVerification
{
    public function state(AdPlatformAdapter $adapter, ?AdConnection $connection): string
    {
        if (! $adapter->isImplemented() || ! $adapter->isConfigured($connection)) {
            return IntegrationState::NOT_CONFIGURED;
        }

        if ($connection->isSandbox()) {
            return $connection->last_sync_ok_at ? IntegrationState::SANDBOX_VERIFIED : IntegrationState::CODE_VERIFIED;
        }

        if ($connection->last_sync_ok_at) {
            $fresh = $connection->last_sync_ok_at->greaterThan(now()->subDay());

            return app()->environment('production') && $fresh && blank($connection->last_error)
                ? IntegrationState::PRODUCTION_VERIFIED
                : IntegrationState::LIVE_VERIFIED;
        }

        return $connection->last_test_ok_at ? IntegrationState::READY : IntegrationState::CODE_VERIFIED;
    }
}
