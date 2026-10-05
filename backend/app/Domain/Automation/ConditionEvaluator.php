<?php

namespace App\Domain\Automation;

use App\Models\Lead;
use Illuminate\Support\Carbon;

/**
 * Decides whether a lead matches a rule's conditions.
 *
 * Deliberately small and total: every operator is listed here, an unknown one
 * is false rather than an exception, and nothing it reads can mutate. A rule
 * that cannot be understood must not fire — silently doing nothing is the only
 * safe failure for something a tenant wrote through a form.
 *
 * Conditions are a flat list combined with AND, which is what a rule builder
 * can express honestly. Nested boolean trees are a second rule, not a deeper
 * structure nobody can read back.
 */
class ConditionEvaluator
{
    /**
     * @param  array<int,array{field?:string,operator?:string,value?:mixed}>  $conditions
     * @return array{matched:bool, reason:?string}
     */
    public function evaluate(Lead $lead, array $conditions): array
    {
        foreach ($conditions as $condition) {
            $field = (string) ($condition['field'] ?? '');
            $operator = (string) ($condition['operator'] ?? '');

            if ($field === '' || $operator === '') {
                return ['matched' => false, 'reason' => 'A condition is missing its field or operator.'];
            }

            $actual = $this->read($lead, $field);
            $expected = $condition['value'] ?? null;

            if (! $this->compare($actual, $operator, $expected)) {
                return [
                    'matched' => false,
                    'reason' => sprintf(
                        '%s (%s) did not satisfy "%s %s"',
                        $field,
                        $this->describe($actual),
                        $operator,
                        $this->describe($expected),
                    ),
                ];
            }
        }

        return ['matched' => true, 'reason' => null];
    }

    /**
     * The fields a rule may read. An allow-list, not reflection over the model:
     * a rule written in a form must never be able to reach a password hash, a
     * token, or another tenant's row by naming a relation.
     */
    public function read(Lead $lead, string $field): mixed
    {
        return match ($field) {
            'status' => $lead->status?->value,
            'temperature' => $lead->temperature?->value,
            'qualification_status' => $lead->qualification_status?->value,
            'score' => (int) $lead->score,
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
            default => null,
        };
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
            'exists' => $actual !== null && $actual !== '' && $actual !== false,
            'not_exists' => $actual === null || $actual === '' || $actual === false,
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
