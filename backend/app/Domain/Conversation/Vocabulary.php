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

    /** @var array<string,list<string>> */
    public const VALUES = [
        self::INTENT => ['ready_to_buy', 'interested', 'inquiring', 'comparing', 'callback_request', 'not_interested', 'wrong_contact'],
        self::OBJECTION => ['price_high', 'location', 'financing', 'timing', 'trust', 'competitor', 'family_decision'],
        self::URGENCY => ['high', 'medium', 'low'],
        self::SERVICE => ['villa', 'apartment', 'townhouse', 'land', 'commercial'],
        self::BUDGET => ['stated', 'tight', 'flexible'],
        self::APPOINTMENT => ['requested', 'interested'],
        self::SENTIMENT => ['positive', 'neutral', 'negative'],
    ];

    /** One value at a time (the latest wins); an objection, by contrast, accumulates. */
    public const SINGLE = [self::INTENT, self::URGENCY, self::SERVICE, self::BUDGET, self::APPOINTMENT, self::SENTIMENT];

    /** When somebody plans to buy, if they said. */
    public const TIMELINES = ['immediate', 'within_1_month', 'within_3_months', 'within_6_months', 'later'];

    public static function valid(string $kind, string $value): bool
    {
        return in_array($value, self::VALUES[$kind] ?? [], true);
    }
}
