<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A digest, rendered. The composer decides what is in it; this only dresses it. */
class DigestMail extends Mailable
{
    /**
     * @param  array<string,mixed>  $digest
     * @param  list<array{title:string,body:string}>  $rollup
     */
    public function __construct(public array $digest, public array $rollup, public ?string $workspace = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->digest['title']);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.digest', with: [
            'title' => $this->digest['title'],
            'digest' => $this->digest,
            'rollup' => $this->rollup,
            'workspace' => $this->workspace,
            'link' => rtrim((string) config('integrations.app_url'), '/'),
        ]);
    }
}
