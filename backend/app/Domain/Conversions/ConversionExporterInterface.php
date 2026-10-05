<?php

namespace App\Domain\Conversions;

use App\Models\ConversionExport;
use App\Models\Integration;

/**
 * One ad platform's offline conversion upload.
 *
 * Adding Snapchat means writing one class. Nothing upstream — the listener, the
 * queue, the retry policy — knows which platform it is talking to.
 */
interface ConversionExporterInterface
{
    /** Matches App\Enums\IntegrationProvider values. */
    public function platform(): string;

    /**
     * Can this actually reach the platform right now?
     *
     * False is not a failure. A workspace that has not connected its ad account
     * has not done anything wrong, and the queue treats "not configured" as a
     * state to wait in rather than an error to retry.
     */
    public function isConfigured(?Integration $integration): bool;

    /** Credential keys the workspace must supply, for the settings form. */
    public function requiredCredentials(): array;

    /**
     * Send one conversion.
     *
     * @return array{ok: bool, summary: array<string,mixed>, error?: string}
     *                                                                       `summary` is what was sent with every personal identifier
     *                                                                       removed, so a trace can be kept without keeping the customer's
     *                                                                       phone number in a second place.
     */
    public function send(ConversionExport $export, Integration $integration): array;
}
