<?php

namespace App\Services\Whatsapp;

use App\DataTransferObjects\SentMessage;
use App\Models\Integration;

interface WhatsappProviderInterface
{
    /** Send a plain text message to a normalised phone number. */
    public function sendText(Integration $integration, string $toPhone, string $body): SentMessage;

    /**
     * Whether an inbound delivery really came from the provider.
     *
     * Returning false must mean "reject", never "cannot tell": an endpoint that
     * accepts unverified payloads lets anyone write into a customer's CRM.
     */
    public function verifySignature(Integration $integration, string $rawBody, ?string $signature): bool;

    public function key(): string;
}
