<?php

namespace App\Domain\Mail;

/**
 * Reads a "your message could not be delivered" notice and says which address failed and whether it is gone for good.
 *
 * Only a hard failure for the address itself is acted on: the mailbox does not exist, or the domain cannot receive mail.
 * A full mailbox, a temporary error, or a refusal about us (spam policy, our own reputation) says nothing about the
 * address, so the lead is never marked dead for it. The failed address is taken from the notice's own lines, never from
 * the original message attached to it, whose To: would only be the same address by luck.
 */
class DeliveryReport
{
    /** @return array{email: string, reason: string}|null  null when this is not a bounce, or not a hard one for the address */
    public static function parse(string $raw): ?array
    {
        $text = self::readable($raw);

        if (! preg_match('/(delivery status notification|undelivered mail returned|mail delivery (?:failed|system|subsystem)|returned mail|failure notice|address not found|couldn.t be found)/i', $text)) {
            return null;
        }

        $email = self::failedRecipient($text);

        if ($email === null) {
            return null;
        }

        // About us or the moment, not about the address.
        if (preg_match('/\b5\.7\.\d+\b|\b4\.\d\.\d+\b|\b552\b.*(?:quota|storage|full)|mailbox (?:is )?full|over quota|temporar|try again later|greylist|blocked|spam|policy|reputation|rate.?limit|too many/i', $text)) {
            return null;
        }

        if (! preg_match('/\b5\.1\.[0-9]\b|\b5\.5\.0\b|\b5\.4\.1\b|\b550\b|does not exist|no such user|nosuchuser|user unknown|unknown user|address not found|address rejected|mailbox unavailable|recipient (?:address )?rejected|invalid (?:recipient|address)|account (?:is )?(?:disabled|not found)/i', $text)) {
            return null;
        }

        return ['email' => $email, 'reason' => self::reason($text)];
    }

    private static function failedRecipient(string $text): ?string
    {
        $patterns = [
            '/Final-Recipient:\s*rfc822;\s*<?([^\s<>;]+@[^\s<>;]+)>?/i',
            '/Original-Recipient:\s*rfc822;\s*<?([^\s<>;]+@[^\s<>;]+)>?/i',
            '/wasn.t delivered to\s+<?([^\s<>]+@[^\s<>]+?)>?\s+because/i',
            '/^\s*<([^\s<>]+@[^\s<>]+)>:\s/m',
            '/could not be delivered to\s+<?([^\s<>]+@[^\s<>]+)>?/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $email = mb_strtolower(rtrim(trim($m[1]), '.,;:'));

                return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
            }
        }

        return null;
    }

    private static function reason(string $text): string
    {
        foreach (['/said:\s*(.+)/i', '/The response was:\s*(.+)/is', '/Diagnostic-Code:\s*(.+)/i', '/(Address not found.*)/i'] as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return mb_substr(trim(preg_replace('/\s+/', ' ', $m[1]) ?? ''), 0, 200);
            }
        }

        return 'Undeliverable';
    }

    /** The message as text a pattern can read: undo quoted-printable and the line wraps of a long reply. */
    private static function readable(string $raw): string
    {
        $text = str_contains($raw, '=\r\n') || str_contains($raw, "=\n") || preg_match('/=[0-9A-F]{2}/', $raw) ? quoted_printable_decode($raw) : $raw;

        return str_replace("\r\n", "\n", $text);
    }
}
