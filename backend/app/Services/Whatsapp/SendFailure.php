<?php

namespace App\Services\Whatsapp;

/**
 * Whether a failed send is worth trying again.
 *
 * The distinction is the whole feature. A send that failed because the network
 * dropped is a message the customer should still get; a send that failed
 * because the number is not on WhatsApp is a message that will never arrive,
 * and retrying it four times only delays telling the rep the truth.
 *
 * So the transient list is explicit and everything else is permanent. Erring
 * the other way — retrying whatever we do not recognise — means a lead who
 * blocked the business gets three more attempts, which is the behaviour that
 * gets a workspace's number reported, and a reported number ends the channel
 * for them.
 */
final class SendFailure
{
    /**
     * Codes that describe the attempt rather than the message.
     *
     * `transport_error` is ours, raised when the HTTP call itself threw. The
     * numeric ones are Meta's: the 5xx family and the two rate-limit codes,
     * which mean "not now" rather than "not ever".
     *
     * @var array<int, string>
     */
    private const TRANSIENT = [
        'transport_error',
        '429', '500', '502', '503', '504',
        '130429',   // Rate limit hit
        '131000',   // Something went wrong (Meta's own generic failure)
        '131016',   // Service temporarily unavailable
        '133016',   // Too many attempts, try later
    ];

    /**
     * Meta's "re-engagement message": free text sent outside the 24-hour window.
     *
     * Permanent, but not the same kind of permanent as an undeliverable number.
     * Nothing is wrong with the lead or the workspace; the message simply has to
     * be a template. Kept apart so the screen can say that instead of showing a
     * bare code.
     */
    public const OUTSIDE_WINDOW = '131047';

    public static function isTransient(?string $code): bool
    {
        return $code !== null && in_array($code, self::TRANSIENT, true);
    }

    public static function isOutsideWindow(?string $code): bool
    {
        return $code === self::OUTSIDE_WINDOW;
    }
}
