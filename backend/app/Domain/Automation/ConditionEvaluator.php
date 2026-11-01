<?php

namespace App\Domain\Automation;

use App\Domain\Leads\LeadClassification;
use App\Domain\Leads\LeadTags;
use App\Models\Call;
use App\Models\CustomFieldValue;
use App\Models\LandingForm;
use App\Models\Lead;
use App\Models\LeadMessage;
use Illuminate\Support\Carbon;

/**
 * Decides whether a lead matches a rule's conditions.
 *
 * Deliberately small and total: every operator is listed here, an unknown one
 * is false rather than an exception, and nothing it reads can mutate. A rule
 * that cannot be understood must not fire — silently doing nothing is the only
 * safe failure for something a tenant wrote through a form.
 *
 * Conditions are a list, and the rule says whether it needs all of them or
 * any one of them. An item may also be a bracket — `{group: all|any,
 * conditions: [...]}` — so "(Riyadh or Jeddah) and budget over a million" is
 * one rule, not two kept in step by hand. One level deep on purpose: a bracket
 * inside a bracket is a structure nobody reads back correctly in a form.
 */
class ConditionEvaluator
{
    public const MATCH_ALL = 'all';

    public const MATCH_ANY = 'any';

    /** @return array<int,string> */
    public static function matches(): array
    {
        return [self::MATCH_ALL, self::MATCH_ANY];
    }

    /**
     * `$context` is the event's own — the only thing a rule can read that is
     * not on the lead. A stage change is visible in `status` afterwards; an
     * outcome like "no answer" changes nothing, so the only record of it is
     * the event that carried it.
     *
     * @param  array<int,array{field?:string,operator?:string,value?:mixed}>  $conditions
     * @param  array<string,scalar|null>  $context
     * @return array{matched:bool, reason:?string}
     */
    public function evaluate(Lead $lead, array $conditions, array $context = [], string $match = self::MATCH_ALL): array
    {
        $any = $match === self::MATCH_ANY;
        $failures = [];

        foreach ($conditions as $condition) {
            if (is_array($condition) && isset($condition['group'])) {
                $inner = $this->evaluateGroup($lead, $condition, $context);

                if ($inner['malformed']) {
                    return ['matched' => false, 'reason' => $inner['reason']];
                }

                if ($inner['matched']) {
                    if ($any) {
                        return ['matched' => true, 'reason' => null];
                    }

                    continue;
                }

                if (! $any) {
                    return ['matched' => false, 'reason' => $inner['reason']];
                }

                $failures[] = $inner['reason'];

                continue;
            }

            $field = (string) ($condition['field'] ?? '');
            $operator = (string) ($condition['operator'] ?? '');

            /*
             * A malformed condition stops the rule whichever mode it is in.
             * Under "any" it would otherwise be skipped over in silence, and a
             * rule that fires on the strength of the conditions somebody could
             * still read is worse than one that refuses to fire at all.
             */
            if ($field === '' || $operator === '') {
                return ['matched' => false, 'reason' => 'A condition is missing its field or operator.'];
            }

            $actual = $this->read($lead, $field, $context);
            $expected = $condition['value'] ?? null;

            if ($this->compare($actual, $operator, $expected)) {
                if ($any) {
                    return ['matched' => true, 'reason' => null];
                }

                continue;
            }

            $failure = sprintf(
                '%s (%s) did not satisfy "%s %s"',
                $field,
                $this->describe($actual),
                $operator,
                $this->describe($expected),
            );

            if (! $any) {
                return ['matched' => false, 'reason' => $failure];
            }

            $failures[] = $failure;
        }

        // An empty list matches in both modes: a rule with a trigger and no
        // conditions fires on every event of that kind, which is a thing people
        // legitimately want and the API deliberately allows.
        if ($any && $failures !== []) {
            return [
                'matched' => false,
                'reason' => 'None of the conditions matched: '.implode('; ', $failures),
            ];
        }

        return ['matched' => true, 'reason' => null];
    }

    /**
     * A bracket: its own all/any over plain conditions. A bracket inside a
     * bracket, or an empty one, is malformed and stops the rule — the same as a
     * condition with no field.
     *
     * @return array{matched:bool, reason:?string, malformed:bool}
     */
    private function evaluateGroup(Lead $lead, array $group, array $context): array
    {
        $inner = (array) ($group['conditions'] ?? []);
        $match = ($group['group'] ?? null) === self::MATCH_ANY ? self::MATCH_ANY : self::MATCH_ALL;

        if ($inner === [] || collect($inner)->contains(fn ($c) => ! is_array($c) || isset($c['group']))) {
            return ['matched' => false, 'reason' => 'A condition group is empty or nested too deep.', 'malformed' => true];
        }

        $verdict = $this->evaluate($lead, $inner, $context, $match);

        return $verdict + ['malformed' => $verdict['reason'] === 'A condition is missing its field or operator.'];
    }

    /**
     * The fields a rule may read. An allow-list, not reflection over the model:
     * a rule written in a form must never be able to reach a password hash, a
     * token, or another tenant's row by naming a relation.
     */
    public function read(Lead $lead, string $field, array $context = []): mixed
    {
        return match ($field) {
            /*
             * Not a column: the outcome the rep just recorded, carried on the
             * event. Without it `lead.outcome_recorded` fires for every button
             * in the work queue and a rule cannot tell "no answer" from "won".
             */
            'last_outcome' => $context['outcome'] ?? null,
            'status' => $lead->status?->value,
            'temperature' => $lead->temperature?->value,
            'qualification_status' => $lead->qualification_status?->value,
            'score' => (int) $lead->score,
            'interest_level' => $lead->interest_level,
            'interest_score' => (int) $lead->interest_score,
            'platform' => $lead->platform,
            'source' => $lead->source,
            'campaign_name' => $lead->campaign_name,
            'project_id' => $lead->project?->uuid,
            'city' => $lead->city,
            'budget_min' => $lead->budget_min,
            'budget_max' => $lead->budget_max,
            'purchase_purpose' => $lead->purchase_purpose,
            'payment_method' => $lead->payment_method,
            'financing_status' => $lead->financing_status,
            'purchase_timeline' => $lead->purchase_timeline,
            'preferred_property_type' => $lead->preferred_property_type,
            'preferred_location' => $lead->preferred_location,
            'appointment_interest' => $lead->appointment_interest,
            'assigned_to' => $lead->assignee?->uuid,
            'is_assigned' => $lead->assigned_to !== null,
            'has_phone' => filled($lead->phone_normalized),
            'has_email' => filled($lead->email),
            'has_whatsapp' => filled($lead->whatsapp),
            're_engaged_count' => (int) $lead->re_engaged_count,
            'minutes_since_created' => $this->minutesSince($lead->created_at),
            'minutes_since_first_contact' => $this->minutesSince($lead->first_contact_at),
            'minutes_since_last_contact' => $this->minutesSince($lead->last_contact_at),
            'was_contacted' => $lead->first_contact_at !== null,
            'has_replied' => $lead->first_response_at !== null,
            'is_priority' => (bool) $lead->is_priority,
            'classification' => LeadClassification::for($lead)['key'] ?? null,
            'language' => $lead->language(),
            'provider' => $lead->provider,
            'campaign_id' => $lead->campaign_id,
            'ad_name' => $lead->ad_name,
            'automation_state' => $lead->automation_state,
            'tags' => LeadTags::of($lead),
            // The page's address, what a manager reads in the rule; null for a lead no page brought.
            'landing_page' => $lead->landing_form_id ? LandingForm::withoutGlobalScopes()->whereKey($lead->landing_form_id)->value('slug') : null,
            'lead_source' => $lead->provider,
            'utm_source' => $lead->utm_source,
            'utm_medium' => $lead->utm_medium,
            'utm_campaign' => $lead->utm_campaign,
            'utm_content' => $lead->utm_content,
            'utm_term' => $lead->utm_term,
            // How many times anybody — rep or rule — has tried to reach them.
            'attempts' => LeadMessage::where('lead_id', $lead->id)->where('direction', 'outbound')->count()
                + Call::where('lead_id', $lead->id)->count(),
            // Carried on a "no reply" signal: how long they have been quiet.
            'no_reply_minutes' => $context['no_reply_minutes'] ?? null,
            default => str_starts_with($field, 'custom.') ? $this->custom($lead, substr($field, 7)) : null,
        };
    }

    /** An answer the lead gave to a question the form or the sheet asked. */
    private function custom(Lead $lead, string $key): ?string
    {
        return CustomFieldValue::where('lead_id', $lead->id)
            ->whereHas('field', fn ($q) => $q->where('key', $key))
            ->value('value');
    }

    private function minutesSince(?Carbon $at): ?int
    {
        return $at ? (int) $at->diffInMinutes(now()) : null;
    }

    private function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            'equals' => $this->looselyEqual($actual, $expected),
            'not_equals' => ! $this->looselyEqual($actual, $expected),
            'contains' => $this->contains($actual, $expected),
            'not_contains' => ! $this->contains($actual, $expected),
            'in' => is_array($expected)
                && collect($expected)->contains(fn ($v) => $this->looselyEqual($actual, $v)),
            'not_in' => is_array($expected)
                && ! collect($expected)->contains(fn ($v) => $this->looselyEqual($actual, $v)),
            // Numeric comparisons against a null reading are false, never true:
            // "score greater than 50" must not match a lead with no score.
            'greater_than' => $this->numeric($actual) !== null
                && $this->numeric($expected) !== null
                && $this->numeric($actual) > $this->numeric($expected),
            'less_than' => $this->numeric($actual) !== null
                && $this->numeric($expected) !== null
                && $this->numeric($actual) < $this->numeric($expected),
            'between' => $this->between($actual, $expected),
            'exists' => $actual !== null && $actual !== '' && $actual !== false && $actual !== [],
            'not_exists' => $actual === null || $actual === '' || $actual === false || $actual === [],
            default => false,
        };
    }

    private function looselyEqual(mixed $actual, mixed $expected): bool
    {
        if (is_bool($actual) || is_bool($expected)) {
            return filter_var($actual, FILTER_VALIDATE_BOOLEAN) === filter_var($expected, FILTER_VALIDATE_BOOLEAN);
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        return is_scalar($actual) && is_scalar($expected)
            && mb_strtolower((string) $actual) === mb_strtolower((string) $expected);
    }

    private function contains(mixed $actual, mixed $expected): bool
    {
        // A list (the tags) contains a value when one of its items is that value — "VIP" is not in ["VIP-old"].
        if (is_array($actual)) {
            return is_scalar($expected) && LeadTags::has(array_map('strval', array_filter($actual, 'is_scalar')), (string) $expected);
        }

        if (! is_scalar($actual) || ! is_scalar($expected)) {
            return false;
        }

        return str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected));
    }

    private function between(mixed $actual, mixed $expected): bool
    {
        if (! is_array($expected) || count($expected) !== 2) {
            return false;
        }

        $value = $this->numeric($actual);
        $low = $this->numeric($expected[0]);
        $high = $this->numeric($expected[1]);

        return $value !== null && $low !== null && $high !== null
            && $value >= $low && $value <= $high;
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /** Short, safe rendering for the trace — never the whole value. */
    private function describe(mixed $value): string
    {
        return match (true) {
            $value === null => 'empty',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => '['.implode(', ', array_map(fn ($v) => (string) $v, array_slice($value, 0, 5))).']',
            default => mb_substr((string) $value, 0, 60),
        };
    }
}
