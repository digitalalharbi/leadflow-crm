<?php

namespace App\Domain\Conversation;

/**
 * The closed lists a fact's value may come from.
 *
 * Whatever produces a fact — Claude or the rules — is held to these. A value
 * outside its list is dropped, never stored: free text from a model must not
 * become something the product acts on.
 */
final class Vocabulary
{
    public const INTENT = 'intent';

    public const OBJECTION = 'objection';

    public const URGENCY = 'urgency';

    public const SERVICE = 'service_interest';

    public const BUDGET = 'budget_signal';

    public const APPOINTMENT = 'appointment_intent';

    public const SENTIMENT = 'sentiment';

    // The real estate facts a customer volunteers in a sentence.
    public const TRANSACTION = 'transaction';

    public const PAYMENT = 'payment';

    public const PURPOSE = 'purpose';

    public const RESERVATION = 'reservation_intent';

    /** Free-valued, validated by shape rather than by a list: a number of rooms, a district's name. */
    public const BEDROOMS = 'bedrooms';

    public const DISTRICT = 'district';

    /** @var array<string,list<string>> */
    public const VALUES = [
        self::INTENT => ['ready_to_buy', 'interested', 'inquiring', 'comparing', 'callback_request', 'not_interested', 'wrong_contact'],
        self::OBJECTION => ['price_high', 'location', 'financing', 'timing', 'trust', 'competitor', 'family_decision'],
        self::URGENCY => ['high', 'medium', 'low'],
        self::SERVICE => ['villa', 'apartment', 'townhouse', 'land', 'commercial'],
        self::BUDGET => ['stated', 'tight', 'flexible'],
        self::APPOINTMENT => ['requested', 'interested'],
        self::SENTIMENT => ['positive', 'neutral', 'negative'],
        self::TRANSACTION => ['buy', 'rent'],
        self::PAYMENT => ['cash', 'financing'],
        self::PURPOSE => ['residence', 'investment'],
        self::RESERVATION => ['ready', 'interested'],
    ];

    /** One value at a time (the latest wins); an objection, by contrast, accumulates. */
    public const SINGLE = [self::INTENT, self::URGENCY, self::SERVICE, self::BUDGET, self::APPOINTMENT, self::SENTIMENT, self::TRANSACTION, self::PAYMENT, self::PURPOSE, self::RESERVATION, self::BEDROOMS, self::DISTRICT];

    /** When somebody plans to buy, if they said. */
    public const TIMELINES = ['immediate', 'within_1_month', 'within_3_months', 'within_6_months', 'later'];

    public static function valid(string $kind, string $value): bool
    {
        return match ($kind) {
            // 1–20 rooms: a number somebody could say, not a phone number that slipped through.
            self::BEDROOMS => ctype_digit($value) && (int) $value >= 1 && (int) $value <= 20,
            // A name: letters and spaces, short. Never a sentence, never markup.
            self::DISTRICT => mb_strlen($value) >= 2 && mb_strlen($value) <= 40 && preg_match('/^[\p{L}\s\-]+$/u', $value) === 1,
            default => in_array($value, self::VALUES[$kind] ?? [], true),
        };
    }
}
