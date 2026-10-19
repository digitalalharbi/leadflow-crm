<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Email;

/**
 * One email to one lead.
 *
 * HTML with a plain-text twin — a message that is only HTML is the first thing
 * a spam filter marks down — a one-click unsubscribe the mail client can offer
 * by itself, and a Message-ID we chose, so a retry that crashed after sending
 * can be recognised rather than sent twice under a different identity.
 */
class LeadEmail extends Mailable
{
    public function __construct(
        public string $mailSubject,
        public string $htmlBody,
        public string $plainBody,
        public string $senderAddress,
        public string $senderName,
        public ?string $replyAddress,
        public string $messageId,
        public string $unsubscribeUrl,
        public ?string $inReplyTo = null,
    ) {}

    public function build(): static
    {
        $this->from($this->senderAddress, $this->senderName)
            ->subject($this->mailSubject)
            ->html($this->htmlBody);

        if (filled($this->replyAddress)) {
            $this->replyTo($this->replyAddress);
        }

        return $this->withSymfonyMessage(function (Email $message) {
            $message->text($this->plainBody);

            $headers = $message->getHeaders();
            $headers->addIdHeader('Message-ID', $this->messageId);

            // The thread: the customer's client shows this under the email it
            // answers, so the conversation reads as one conversation.
            if (filled($this->inReplyTo)) {
                $headers->addIdHeader('In-Reply-To', $this->inReplyTo);
                $headers->addIdHeader('References', $this->inReplyTo);
            }
            $headers->addTextHeader('List-Unsubscribe', '<'.$this->unsubscribeUrl.'>');
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        });
    }
}
