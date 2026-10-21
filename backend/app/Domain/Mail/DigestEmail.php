<?php

namespace App\Domain\Mail;

/** A digest, rendered. The composer decides what is in it; this only dresses it. */
class DigestEmail
{
    /**
     * @param  array<string,mixed>  $digest
     * @param  list<array{title:string,body:string}>  $rollup
     * @return array{subject:string,html:string}
     */
    public static function compose(array $digest, array $rollup, ?string $workspace = null): array
    {
        $html = view('emails.digest', [
            'title' => $digest['title'],
            'digest' => $digest,
            'rollup' => $rollup,
            'workspace' => $workspace,
            'link' => rtrim((string) config('integrations.app_url'), '/'),
        ])->render();

        return ['subject' => (string) $digest['title'], 'html' => $html];
    }
}
