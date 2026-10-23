<?php

namespace App\Domain\Tracking\Forms;

use Illuminate\Support\Carbon;

/**
 * A smart form is a list of fields, and the list is the contract.
 *
 * It says what may be asked (and nothing else is ever accepted), what must be answered, what shows only
 * after something else was chosen, how each answer is checked, and where it lands on the lead: a core field,
 * a qualification answer, a custom field, or tracking metadata that never touches the lead's own answers.
 * Pure logic — no database, no request — so every rule can be pinned down on its own.
 */
class FormSchema
{
    public const TYPES = ['text', 'phone', 'email', 'number', 'select', 'multi_select', 'radio', 'checkbox', 'date', 'textarea', 'hidden'];

    public const MAX_FIELDS = 40;

    /** Lead columns a field may fill: the core ones, then what qualification asks. */
    public const LEAD_TARGETS = [
        'full_name', 'phone', 'email', 'city',
        'budget_min', 'budget_max', 'purchase_purpose', 'payment_method', 'preferred_property_type', 'preferred_location', 'purchase_timeline',
        'transaction_type', 'preferred_district', 'bedrooms', 'area_sqm', 'nationality', 'preferred_channel', 'reservation_intent',
    ];

    /** What a form can say about the visit itself, kept apart from what the visitor answered. */
    public const TRACKING_TARGETS = ['source', 'medium', 'campaign', 'content', 'term', 'project', 'unit', 'link', 'landing_url', 'referrer'];

    private const TEXT_MAX = 255;

    private const TEXTAREA_MAX = 2000;

    // ---------------------------------------------------------------- the definition

    /**
     * What is wrong with this list of fields, keyed by the field it is about ("_form" for the form as a whole).
     *
     * @param  list<array<string,mixed>>  $fields
     * @return array<string,string>
     */
    public function definitionErrors(array $fields): array
    {
        $errors = [];
        $keys = [];
        $targets = [];

        if (count($fields) > self::MAX_FIELDS) {
            $errors[(string) ($fields[self::MAX_FIELDS]['key'] ?? 'f'.(self::MAX_FIELDS + 1))] = 'too_many_fields';
        }

        foreach ($fields as $field) {
            $key = (string) ($field['key'] ?? '');
            $at = $key !== '' ? $key : '_form';

            if (! preg_match('/^[a-z][a-z0-9_]{0,30}$/', $key)) {
                $errors[$at] = 'invalid_key';

                continue;
            }

            if (isset($keys[$key])) {
                $errors[$key] = 'duplicate_key';

                continue;
            }

            $keys[$key] = true;
            $type = (string) ($field['type'] ?? '');

            if (! in_array($type, self::TYPES, true)) {
                $errors[$key] = 'invalid_type';
            } elseif (in_array($type, ['select', 'multi_select', 'radio'], true) && $this->options($field) === []) {
                $errors[$key] = 'options_required';
            } elseif (trim((string) ($field['label'] ?? '')) === '' && $type !== 'hidden') {
                $errors[$key] = 'label_required';
            } elseif (! empty($field['hidden']) && ! empty($field['required'])) {
                $errors[$key] = 'hidden_cannot_be_required';
            }

            $mapping = (string) ($field['mapping'] ?? 'none');

            if (! $this->mappingIsKnown($mapping)) {
                $errors[$key] ??= 'invalid_mapping';
            } elseif (str_starts_with($mapping, 'lead.') || str_starts_with($mapping, 'tracking.')) {
                if (isset($targets[$mapping])) {
                    $errors[$key] ??= 'mapping_already_used';
                }
                $targets[$mapping] = true;
            }
        }

        foreach ($fields as $field) {
            $key = (string) ($field['key'] ?? '');
            $condition = $field['visible_if'] ?? null;

            if ($condition !== null && (! is_array($condition) || ! isset($keys[(string) ($condition['field'] ?? '')]) || ($condition['field'] ?? null) === $key)) {
                $errors[$key] ??= 'invalid_condition';
            }
        }

        // A form that can create a lead nobody can reach is a form that loses the customer.
        $reachable = isset($targets['lead.phone']) || isset($targets['lead.email']);

        if (! $reachable && $errors === []) {
            $errors['_form'] = 'needs_a_way_to_reach_the_lead';
        }

        return $errors;
    }

    /**
     * The list in its final form: ordered, each field complete with its defaults.
     *
     * @param  list<array<string,mixed>>  $fields
     * @return list<array<string,mixed>>
     */
    public function normalize(array $fields): array
    {
        $fields = array_values(array_map(fn (array $f, int $i) => [
            'key' => (string) $f['key'],
            'type' => (string) $f['type'],
            'label' => trim((string) ($f['label'] ?? '')),
            'placeholder' => isset($f['placeholder']) ? trim((string) $f['placeholder']) : null,
            'help' => isset($f['help']) ? trim((string) $f['help']) : null,
            'required' => (bool) ($f['required'] ?? false),
            'hidden' => (bool) ($f['hidden'] ?? false) || ($f['type'] ?? '') === 'hidden',
            'default' => $f['default'] ?? null,
            'options' => $this->options($f),
            'step' => max(1, (int) ($f['step'] ?? 1)),
            'order' => (int) ($f['order'] ?? ($i + 1)),
            'validation' => is_array($f['validation'] ?? null) ? array_filter($f['validation'], fn ($v) => $v !== null && $v !== '') : [],
            'mapping' => (string) ($f['mapping'] ?? 'none'),
            'visible_if' => $f['visible_if'] ?? null,
        ], $fields, array_keys($fields)));

        usort($fields, fn (array $a, array $b) => [$a['step'], $a['order']] <=> [$b['step'], $b['order']]);

        return $fields;
    }

    /**
     * What the visitor's page needs: the fields to draw and the conditions to honour — never where an answer
     * goes, never a hidden field's default. Where an answer lands is the workspace's business.
     *
     * @param  list<array<string,mixed>>  $fields
     * @return list<array<string,mixed>>
     */
    public function publicShape(array $fields): array
    {
        return array_values(array_map(fn (array $f) => array_filter([
            'key' => $f['key'],
            'type' => $f['type'],
            'label' => $f['label'],
            'placeholder' => $f['placeholder'] ?? null,
            'help' => $f['help'] ?? null,
            'required' => (bool) $f['required'],
            'options' => $f['options'] !== [] ? $f['options'] : null,
            'step' => $f['step'] ?? 1,
            'validation' => ($f['validation'] ?? []) !== [] ? $f['validation'] : null,
            'visible_if' => $f['visible_if'] ?? null,
        ], fn ($v) => $v !== null), array_filter($fields, fn (array $f) => ! $f['hidden'])));
    }

    // ---------------------------------------------------------------- a submission

    /**
     * @param  list<array<string,mixed>>  $fields  normalised
     * @param  array<string,mixed>  $input  what the visitor sent
     * @param  array<string,string>  $context  what the server knows about the visit: source, medium, campaign, project, unit, link…
     * @return array{values:array<string,mixed>, errors:array<string,string>, dropped:list<string>}
     */
    public function validateSubmission(array $fields, array $input, array $context): array
    {
        $byKey = [];
        foreach ($fields as $f) {
            $byKey[$f['key']] = $f;
        }

        $dropped = array_values(array_filter(array_keys($input), fn ($k) => ! isset($byKey[$k])));
        $values = [];
        $errors = [];

        foreach ($fields as $f) {
            // A hidden field is the server's: a literal default, or a token resolved from what the server knows.
            if ($f['hidden']) {
                $resolved = $this->resolveDefault($f['default'], $context);

                if ($resolved !== null && $resolved !== '') {
                    $values[$f['key']] = $resolved;
                }

                continue;
            }

            if (! $this->visible($f, $values)) {
                continue;
            }

            $raw = $input[$f['key']] ?? null;

            if ($this->isEmpty($raw)) {
                if ($f['required']) {
                    $errors[$f['key']] = 'required';
                }

                continue;
            }

            [$value, $error] = $this->coerce($f, $raw);

            if ($error !== null) {
                $errors[$f['key']] = $error;
            } else {
                $values[$f['key']] = $value;
            }
        }

        return ['values' => $values, 'errors' => $errors, 'dropped' => $dropped];
    }

    /**
     * The answers onto the lead: `lead.*` columns, `custom.*` fields (with their labels), `tracking.*`
     * metadata — and the whole submission as it came, because no answer is ever lost.
     *
     * @param  list<array<string,mixed>>  $fields
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    public function toLead(array $fields, array $values): array
    {
        $lead = ['custom' => [], 'custom_labels' => [], 'tracking' => []];

        foreach ($fields as $f) {
            if (! array_key_exists($f['key'], $values)) {
                continue;
            }

            $mapping = $f['mapping'];

            match (true) {
                str_starts_with($mapping, 'lead.') => $lead[substr($mapping, 5)] = $values[$f['key']],
                str_starts_with($mapping, 'custom.') => [$lead['custom'][substr($mapping, 7)], $lead['custom_labels'][substr($mapping, 7)]] = [$values[$f['key']], $f['label']],
                str_starts_with($mapping, 'tracking.') => $lead['tracking'][substr($mapping, 9)] = $values[$f['key']],
                default => null,
            };
        }

        $lead['raw'] = $values;

        return $lead;
    }

    // ---------------------------------------------------------------- the rules

    /** @param  array<string,mixed>  $field */
    private function coerce(array $field, mixed $raw): array
    {
        $v = $field['validation'] ?? [];

        return match ($field['type']) {
            'text', 'hidden' => $this->text($raw, self::TEXT_MAX, $v),
            'textarea' => $this->text($raw, self::TEXTAREA_MAX, $v),
            'phone' => $this->phone($raw),
            'email' => is_string($raw) && filter_var(trim($raw), FILTER_VALIDATE_EMAIL) && mb_strlen(trim($raw)) <= 190 ? [trim($raw), null] : [null, 'invalid_email'],
            'number' => $this->number($raw, $v),
            'select', 'radio' => $this->oneOf($field, $raw),
            'multi_select' => $this->manyOf($field, $raw),
            'checkbox' => [filter_var($raw, FILTER_VALIDATE_BOOLEAN), null],
            'date' => $this->date($raw),
            default => [null, 'invalid_type'],
        };
    }

    private function text(mixed $raw, int $max, array $v): array
    {
        if (! is_scalar($raw)) {
            return [null, 'invalid_text'];
        }

        $text = trim((string) $raw);
        $limit = min($max, (int) ($v['max_length'] ?? $max));

        if (mb_strlen($text) > $limit) {
            return [null, 'too_long'];
        }

        if (isset($v['min_length']) && mb_strlen($text) < (int) $v['min_length']) {
            return [null, 'too_short'];
        }

        return [$text, null];
    }

    private function phone(mixed $raw): array
    {
        $digits = is_scalar($raw) ? preg_replace('/[\s\-().]/', '', (string) $raw) : '';

        return is_string($digits) && preg_match('/^\+?[0-9]{7,15}$/', $digits) ? [$digits, null] : [null, 'invalid_phone'];
    }

    private function number(mixed $raw, array $v): array
    {
        if (! is_numeric($raw)) {
            return [null, 'invalid_number'];
        }

        $n = $raw + 0;

        if ((isset($v['min']) && $n < $v['min']) || (isset($v['max']) && $n > $v['max'])) {
            return [null, 'out_of_range'];
        }

        return [$n, null];
    }

    private function oneOf(array $field, mixed $raw): array
    {
        return is_scalar($raw) && in_array((string) $raw, array_column($field['options'], 'value'), true) ? [(string) $raw, null] : [null, 'invalid_option'];
    }

    private function manyOf(array $field, mixed $raw): array
    {
        $allowed = array_column($field['options'], 'value');
        $given = is_array($raw) ? array_values(array_map('strval', $raw)) : [];

        return $given !== [] && array_diff($given, $allowed) === [] ? [$given, null] : [null, 'invalid_option'];
    }

    private function date(mixed $raw): array
    {
        if (! is_string($raw) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return [null, 'invalid_date'];
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $raw)->format('Y-m-d') === $raw ? [$raw, null] : [null, 'invalid_date'];
        } catch (\Throwable) {
            return [null, 'invalid_date'];
        }
    }

    /** @param  array<string,mixed>  $values  the answers so far: a condition reads what was answered before it */
    private function visible(array $field, array $values): bool
    {
        $c = $field['visible_if'] ?? null;

        if (! is_array($c)) {
            return true;
        }

        $answer = $values[$c['field']] ?? null;

        return match (true) {
            array_key_exists('equals', $c) => (string) $answer === (string) $c['equals'],
            isset($c['in']) => in_array((string) $answer, array_map('strval', (array) $c['in']), true),
            ! empty($c['not_empty']) => ! $this->isEmpty($answer),
            default => true,
        };
    }

    private function resolveDefault(mixed $default, array $context): mixed
    {
        if (is_string($default) && str_starts_with($default, '@')) {
            return $context[substr($default, 1)] ?? null;
        }

        return $default;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /** @return list<array{value:string,label:string}> */
    private function options(array $field): array
    {
        return array_values(array_filter(array_map(fn ($o) => is_array($o) && isset($o['value']) ? ['value' => (string) $o['value'], 'label' => (string) ($o['label'] ?? $o['value'])] : null, (array) ($field['options'] ?? []))));
    }

    private function mappingIsKnown(string $mapping): bool
    {
        return match (true) {
            $mapping === 'none' => true,
            str_starts_with($mapping, 'lead.') => in_array(substr($mapping, 5), self::LEAD_TARGETS, true),
            str_starts_with($mapping, 'tracking.') => in_array(substr($mapping, 9), self::TRACKING_TARGETS, true),
            str_starts_with($mapping, 'custom.') => (bool) preg_match('/^[a-z][a-z0-9_]{0,40}$/', substr($mapping, 7)),
            default => false,
        };
    }
}
