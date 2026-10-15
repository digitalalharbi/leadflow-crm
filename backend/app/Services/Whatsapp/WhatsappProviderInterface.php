<?php

namespace App\Services\Whatsapp;

use App\DataTransferObjects\SentMessage;
use App\Models\Integration;

interface WhatsappProviderInterface
{
    /** Send a plain text message to a normalised phone number. */
    public function sendText(Integration $integration, string $toPhone, string $body): SentMessage;

    /**
     * Send a pre-approved template.
     *
     * The only kind of message a business may start a conversation with, or
     * send after the customer has been silent for a day. Plain text outside
     * that window is refused by Meta with code 131047, so a send path that can
     * only do text is one that works in a demo and fails on the first cold lead.
     *
     * @param  array<int,string>  $parameters  body variables, in the order the template declares them
     */
    public function sendTemplate(
        Integration $integration,
        string $toPhone,
        string $templateName,
        string $language,
        array $parameters = [],
    ): SentMessage;

    /**
     * Send a file as an attachment the customer can open inside WhatsApp.
     *
     * By uploaded media rather than by a public link, on purpose. A link makes
     * Meta's own servers fetch it, which would register as the customer
     * opening the brochure — a false engagement signal that raises a score for
     * a person who has not seen anything.
     */
    public function sendDocument(
        Integration $integration,
        string $toPhone,
        string $contents,
        string $mime,
        string $filename,
        ?string $caption = null,
    ): SentMessage;

    /**
     * Whether an inbound delivery really came from the provider.
     *
     * Returning false must mean "reject", never "cannot tell": an endpoint that
     * accepts unverified payloads lets anyone write into a customer's CRM.
     */
    public function verifySignature(Integration $integration, string $rawBody, ?string $signature): bool;

    public function key(): string;
}
