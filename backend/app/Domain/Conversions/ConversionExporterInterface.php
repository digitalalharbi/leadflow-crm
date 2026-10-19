<?php

namespace App\Domain\Conversions;

use App\Models\ConversionExport;

/**
 * One ad platform's offline conversion upload.
 *
 * Takes a plain bag of credentials rather than a particular record, because a
 * workspace may have given them on the lead-source integration or on the ad
 * connection — see ConversionCredentials, which merges the two.
 */
interface ConversionExporterInterface
{
    /** An App\Domain\Campaigns\AdPlatform value. */
    public function platform(): string;

    /** False is not a failure: an unconnected account is a state to wait in, not an error to retry. */
    public function isConfigured(?array $credentials): bool;

    /** @return list<string> keys the workspace must supply */
    public function requiredCredentials(): array;

    /**
     * Send one conversion.
     *
     * @return array{ok: bool, summary: array<string,mixed>, error?: string}
     *                                                                       `summary` is what was sent with every personal identifier removed.
     */
    public function send(ConversionExport $export, array $credentials): array;
}
