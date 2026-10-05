<?php

namespace App\Services\Whatsapp;

/**
 * Will trying again help?
 *
 * The distinction matters more than it looks. Retrying a permanent failure is
 * how a customer receives the same message five times — an invalid number does
 * not become valid, and a template Meta rejected does not get approved by
 * asking again. Retrying a transient one is the difference between a message
 * that arrives a minute late and a message the rep has to notice and retype.
 *
 * So the default is deliberately *not* to retry: an unrecognised code is
 * treated as permanent. Sending a customer four copies of a message is a
 * complaint; a message that silently did not retry is a failed message sitting
 * visibly in the conversation, which somebody can act on.
 */
class FailureClassifier
{
    /**
     * Meta error codes that pass on their own.
     *
     * 131_026 is "message undeliverable", which Meta returns both for a number
     * that is not on WhatsApp and for a conversation that has momentarily
     * lapsed — so it is excluded rather than guessed at.
     */
    private const TRANSIENT_CODES = [
        'transport_error',   // we never reached Meta at all
        '500', '502', '503', '504',
        '131_000',           // Meta's own generic "something went wrong"
        '131000',
        '4',                 // application request limit reached
        '80007',             // rate limit hit
        '133016',            // temporarily blocked for restrictions
    ];

    public function isTransient(?string $code): bool
    {
        if ($code === null) {
            return false;
        }

        return in_array($code, self::TRANSIENT_CODES, true);
    }
}
