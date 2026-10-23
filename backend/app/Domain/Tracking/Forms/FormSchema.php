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
    public const TYPES = [
        'text', 'phone', 'email', 'number', 'select', 'multi_select', 'radio', 'checkbox', 'date', 'time', 'textarea', 'hidden',
        'heading', 'paragraph', 'divider', 'consent', 'matrix', 'repeater',
    ];

    /** What the page shows and nobody answers. */
    public const DISPLAY = ['heading', 'paragraph', 'divider'];

    /** Answers that are a structure, not a value: they land in a custom field (as text) and in the raw submission (as they came). */
    public const STRUCTURED = ['matrix', 'repeater'];

    /** What a repeater's columns may ask. */
    public const SUBFIELD_TYPES = ['text', 'phone', 'email', 'number', 'select', 'date', 'time', 'textarea'];

    public const MAX_FIELDS = 60;

    public const MAX_REPEATER_ROWS = 20;

    public const MAX_MATRIX_ROWS = 20;

    public const MAX_MATRIX_COLUMNS = 7;

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
     * What is wrong with this list of fields, keyed by the field it is about ("_form" for the form as a whole, "_layout" for where
     * the fields sit).
     *
     * @param  list<array<string,mixed>>  $fields
     * @param  array<string,mixed>|null  $layout
     * @return array<string,string>
     */
    public function definitionErrors(array $fields, ?array $layout = null): array
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
            } elseif (trim((string) ($field['label'] ?? '')) === '' && ! in_array($type, ['hidden', 'divider'], true)) {
                $errors[$key] = 'label_required';
            } elseif (! empty($field['hidden']) && ! empty($field['required'])) {
                $errors[$key] = 'hidden_cannot_be_required';
            } elseif ($type === 'matrix' && ($code = $this->matrixError($field)) !== null) {
                $errors[$key] = $code;
            } elseif ($type === 'repeater' && ($code = $this->repeaterError($field)) !== null) {
                $errors[$key] = $code;
            } elseif (($code = $this->validationError($field)) !== null) {
                $errors[$key] = $code;
            }

            $mapping = (string) ($field['mapping'] ?? 'none');

            if (! $this->mappingIsKnown($mapping)) {
                $errors[$key] ??= 'invalid_mapping';
            } elseif (in_array($type, [...self::DISPLAY], true) && $mapping !== 'none') {
                $errors[$key] ??= 'display_cannot_be_mapped';
            } elseif (in_array($type, [...self::STRUCTURED, 'consent'], true) && ! in_array($mapping, ['none'], true) && ! str_starts_with($mapping, 'custom.')) {
                $errors[$key] ??= 'structured_mapping_only_custom';
            } elseif (str_starts_with($mapping, 'lead.') || str_starts_with($mapping, 'tracking.')) {
                if (isset($targets[$mapping])) {
                    $errors[$key] ??= 'mapping_already_used';
                }
                $targets[$mapping] = true;
            }
        }

        $known = array_keys($keys);

        foreach ($fields as $field) {
            $key = (string) ($field['key'] ?? '');
            $condition = $field['visible_if'] ?? null;

            if ($condition !== null && (! is_array($condition) || ! isset($keys[(string) ($condition['field'] ?? '')]) || ($condition['field'] ?? null) === $key)) {
                $errors[$key] ??= 'invalid_condition';
            }

            foreach (['show_when', 'hide_when', 'require_when'] as $when) {
                if (($field[$when] ?? null) !== null && (! is_array($field[$when]) || Logic::errors($field[$when], $known, $key) !== [])) {
                    $errors[$key] ??= 'invalid_condition';
                }
            }
        }

        // A form that can create a lead nobody can reach is a form that loses the customer.
        $reachable = isset($targets['lead.phone']) || isset($targets['lead.email']);

        if (! $reachable && $errors === []) {
            $errors['_form'] = 'needs_a_way_to_reach_the_lead';
        }

        if ($errors === [] && $layout !== null) {
            $layoutErrors = (new FormLayout)->errors($layout, $this->normalize($fields));

            if ($layoutErrors !== []) {
                $errors['_layout'] = $layoutErrors[0];
            }
        }

        return $errors;
    }

    /** @param  array<string,mixed>  $field */
    private function matrixError(array $field): ?string
    {
        $m = (array) ($field['matrix'] ?? []);
        $rows = $this->options(['options' => $m['rows'] ?? []]);
        $columns = $this->options(['options' => $m['columns'] ?? []]);

        return match (true) {
            $rows === [] || count($rows) > self::MAX_MATRIX_ROWS => 'matrix_rows_required',
            count($columns) < 2 || count($columns) > self::MAX_MATRIX_COLUMNS => 'matrix_columns_required',
            count(array_unique(array_column($rows, 'value'))) !== count($rows) || count(array_unique(array_column($columns, 'value'))) !== count($columns) => 'matrix_duplicate_value',
            isset($m['min_answers']) && ((int) $m['min_answers'] < 0 || (int) $m['min_answers'] > count($rows)) => 'matrix_min_answers',
            default => null,
        };
    }

    /** @param  array<string,mixed>  $field */
    private function repeaterError(array $field): ?string
    {
        $r = (array) ($field['repeater'] ?? []);
        $subs = (array) ($r['subfields'] ?? []);

        if ($subs === [] || count($subs) > 6) {
            return 'repeater_subfields_required';
        }

        $seen = [];

        foreach ($subs as $s) {
            $k = (string) ($s['key'] ?? '');

            if (! preg_match('/^[a-z][a-z0-9_]{0,30}$/', $k) || isset($seen[$k]) || ! in_array($s['type'] ?? '', self::SUBFIELD_TYPES, true) || trim((string) ($s['label'] ?? '')) === '') {
                return 'repeater_subfield_invalid';
            }

            if ($s['type'] === 'select' && $this->options($s) === []) {
                return 'repeater_subfield_invalid';
            }

            $seen[$k] = true;
        }

        $min = (int) ($r['min_rows'] ?? 0);
        $max = (int) ($r['max_rows'] ?? self::MAX_REPEATER_ROWS);
        $default = (int) ($r['default_rows'] ?? max(1, $min));

        return $min < 0 || $max < 1 || $max > self::MAX_REPEATER_ROWS || $min > $max || $default > $max ? 'repeater_rows_invalid' : null;
    }

    /** @param  array<string,mixed>  $field */
    private function validationError(array $field): ?string
    {
        $v = (array) ($field['validation'] ?? []);

        if (isset($v['min'], $v['max']) && is_numeric($v['min']) && is_numeric($v['max']) && $v['min'] > $v['max']) {
            return 'invalid_validation';
        }

        if (isset($v['min_length'], $v['max_length']) && $v['min_length'] > $v['max_length']) {
            return 'invalid_validation';
        }

        if (isset($v['pattern']) && $v['pattern'] !== '' && (mb_strlen((string) $v['pattern']) > 120 || @preg_match('/'.str_replace('/', '\/', (string) $v['pattern']).'/u', '') === false)) {
            return 'invalid_pattern';
        }

        return null;
    }

    /**
     * The list in its final form: ordered, each field complete with its defaults.
     *
     * @param  list<array<string,mixed>>  $fields
     * @return list<array<string,mixed>>
     */
    public function normalize(array $fields): array
    {
        $fields = array_values(array_map(function (array $f, int $i) {
            $type = (string) $f['type'];
            $validation = is_array($f['validation'] ?? null) ? array_filter($f['validation'], fn ($v) => $v !== null && $v !== '') : [];

            if (isset($validation['message'])) {
                $validation['message'] = mb_substr(trim(strip_tags((string) $validation['message'])), 0, 160);
            }

            return [
                'key' => (string) $f['key'],
                'type' => $type,
                'label' => mb_substr(trim(strip_tags((string) ($f['label'] ?? ''))), 0, 600),
                'placeholder' => isset($f['placeholder']) ? mb_substr(trim(strip_tags((string) $f['placeholder'])), 0, 160) : null,
                'help' => isset($f['help']) ? mb_substr(trim(strip_tags((string) $f['help'])), 0, 300) : null,
                'required' => (bool) ($f['required'] ?? false) && ! in_array($type, self::DISPLAY, true),
                'hidden' => (bool) ($f['hidden'] ?? false) || $type === 'hidden',
                'default' => $f['default'] ?? null,
                'options' => $this->options($f),
                'step' => max(1, (int) ($f['step'] ?? 1)),
                'order' => (int) ($f['order'] ?? ($i + 1)),
                'validation' => $validation,
                'mapping' => (string) ($f['mapping'] ?? 'none'),
                'visible_if' => $f['visible_if'] ?? null,
                // Conditions: the first generation's `visible_if` is a `show_when` too, so everything downstream reads one thing.
                'show_when' => Logic::clean($f['show_when'] ?? null) ?? (is_array($f['visible_if'] ?? null) ? Logic::clean(Logic::fromLegacy($f['visible_if'])) : null),
                'hide_when' => Logic::clean($f['hide_when'] ?? null),
                'require_when' => Logic::clean($f['require_when'] ?? null),
                'matrix' => $type === 'matrix' ? $this->cleanMatrix((array) ($f['matrix'] ?? [])) : null,
                'repeater' => $type === 'repeater' ? $this->cleanRepeater((array) ($f['repeater'] ?? [])) : null,
            ];
        }, $fields, array_keys($fields)));

        usort($fields, fn (array $a, array $b) => [$a['step'], $a['order']] <=> [$b['step'], $b['order']]);

        return $fields;
    }

    /** @param  array<string,mixed>  $m  @return array<string,mixed> */
    private function cleanMatrix(array $m): array
    {
        return [
            'rows' => $this->options(['options' => $m['rows'] ?? []]),
            'columns' => $this->options(['options' => $m['columns'] ?? []]),
            'multiple' => (bool) ($m['multiple'] ?? false),
            'require_all_rows' => (bool) ($m['require_all_rows'] ?? false),
            'min_answers' => max(0, (int) ($m['min_answers'] ?? 0)),
        ];
    }

    /** @param  array<string,mixed>  $r  @return array<string,mixed> */
    private function cleanRepeater(array $r): array
    {
        $min = max(0, (int) ($r['min_rows'] ?? 0));

        return [
            'subfields' => array_values(array_map(fn (array $s) => [
                'key' => (string) $s['key'], 'type' => (string) $s['type'], 'label' => mb_substr(trim(strip_tags((string) $s['label'])), 0, 120),
                'required' => (bool) ($s['required'] ?? false), 'options' => $this->options($s),
                'placeholder' => isset($s['placeholder']) ? mb_substr(trim(strip_tags((string) $s['placeholder'])), 0, 120) : null,
            ], array_filter((array) ($r['subfields'] ?? []), 'is_array'))),
            'min_rows' => $min,
            'max_rows' => min(self::MAX_REPEATER_ROWS, max(1, (int) ($r['max_rows'] ?? 5))),
            'default_rows' => max($min, (int) ($r['default_rows'] ?? max(1, $min))),
            'add_label' => isset($r['add_label']) ? mb_substr(trim(strip_tags((string) $r['add_label'])), 0, 60) : null,
        ];
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
            'show_when' => $f['show_when'] ?? null,
            'hide_when' => $f['hide_when'] ?? null,
            'require_when' => $f['require_when'] ?? null,
            'matrix' => $f['matrix'] ?? null,
            'repeater' => $f['repeater'] ?? null,
        ], fn ($v) => $v !== null), array_filter($fields, fn (array $f) => ! $f['hidden'])));
    }

    // ---------------------------------------------------------------- a submission

    /**
     * @param  list<array<string,mixed>>  $fields  normalised
     * @param  array<string,mixed>  $input  what the visitor sent
     * @param  array<string,string>  $context  what the server knows about the visit: source, medium, campaign, project, unit, link…
     * @param  array{sections:list<array<string,mixed>>}|null  $layout  normalised; derived from the fields when there is none
     * @param  array{default_country?:string}  $options
     * @return array{values:array<string,mixed>, errors:array<string,string>, dropped:list<string>, raw:array<string,string>}
     */
    public function validateSubmission(array $fields, array $input, array $context, ?array $layout = null, array $options = []): array
    {
        $byKey = [];
        foreach ($fields as $f) {
            $byKey[$f['key']] = $f;
        }

        $dropped = array_values(array_filter(array_keys($input), fn ($k) => ! isset($byKey[$k])));
        $state = (new FormLayout)->visibility($fields, $layout ?? (new FormLayout)->derive($fields), $input);
        $values = [];
        $errors = [];
        $raw = [];

        foreach ($fields as $f) {
            // A hidden field is the server's: a literal default, or a token resolved from what the server knows.
            if ($f['hidden']) {
                $resolved = $this->resolveDefault($f['default'], $context);

                if ($resolved !== null && $resolved !== '') {
                    $values[$f['key']] = $resolved;
                }

                continue;
            }

            // Hidden by a condition, a row or a section: whatever came for it is not an answer, and it cannot be demanded.
            if (! in_array($f['key'], $state['visible'], true) || in_array($f['type'], self::DISPLAY, true)) {
                continue;
            }

            $value = $input[$f['key']] ?? null;
            $required = in_array($f['key'], $state['required'], true);

            if (in_array($f['type'], ['checkbox', 'consent'], true)) {
                $ticked = filter_var($value, FILTER_VALIDATE_BOOLEAN);

                if ($required && ! $ticked) {
                    $errors[$f['key']] = 'required';
                } elseif ($value !== null) {
                    $values[$f['key']] = $ticked;
                }

                continue;
            }

            if ($this->isEmpty($value) || ($f['type'] === 'matrix' && $this->matrixIsEmpty($value)) || ($f['type'] === 'repeater' && $this->repeaterIsEmpty($value))) {
                if ($required || ($f['type'] === 'repeater' && (int) ($f['repeater']['min_rows'] ?? 0) > 0) || ($f['type'] === 'matrix' && (int) ($f['matrix']['min_answers'] ?? 0) > 0)) {
                    $errors[$f['key']] = 'required';
                }

                continue;
            }

            if ($f['type'] === 'phone') {
                $raw[$f['key']] = is_array($value) ? (string) ($value['number'] ?? '') : (is_scalar($value) ? (string) $value : '');
            }

            [$coerced, $error] = $this->coerce($f, $value, $options);

            if ($error !== null) {
                $errors[$f['key']] = $error;
            } else {
                $values[$f['key']] = $coerced;
            }
        }

        // A repeater's refusal names its row and column ("guests.1.mobile"): it comes back as the coerced array's second part.
        foreach ($values as $k => $v) {
            if (is_array($v) && isset($v['__errors'])) {
                foreach ($v['__errors'] as $path => $code) {
                    $errors["{$k}.{$path}"] = $code;
                }
                unset($values[$k]);
            }
        }

        return ['values' => $values, 'errors' => $errors, 'dropped' => $dropped, 'raw' => $raw];
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

    /**
     * A custom field holds text: a list of choices reads as the labels the visitor saw, a tick as yes or no, a matrix as one line
     * for each row, a repeater as one line for each person.
     *
     * @param  list<array<string,mixed>>  $fields
     * @param  array<string,mixed>  $custom
     * @return array<string,string>
     */
    public function customAsText(array $fields, array $custom): array
    {
        $byMapping = [];
        foreach ($fields as $f) {
            if (str_starts_with((string) $f['mapping'], 'custom.')) {
                $byMapping[substr($f['mapping'], 7)] = $f;
            }
        }

        $text = [];

        foreach ($custom as $key => $value) {
            $f = $byMapping[$key] ?? null;
            $labels = array_column($f['options'] ?? [], 'label', 'value');

            $text[$key] = match (true) {
                ($f['type'] ?? '') === 'matrix' && is_array($value) => $this->matrixText($f, $value),
                ($f['type'] ?? '') === 'repeater' && is_array($value) => $this->repeaterText($f, $value),
                is_array($value) => implode('، ', array_map(fn ($v) => $labels[$v] ?? (string) $v, $value)),
                is_bool($value) => $value ? 'نعم' : 'لا',
                default => (string) ($labels[(string) $value] ?? $value),
            };
        }

        return $text;
    }

    /** @param  array<string,mixed>  $f  @param  array<string,mixed>  $value */
    private function matrixText(array $f, array $value): string
    {
        $rows = array_column($f['matrix']['rows'], 'label', 'value');
        $cols = array_column($f['matrix']['columns'], 'label', 'value');

        return implode("\n", array_map(fn ($row, $answer) => ($rows[$row] ?? $row).': '.implode('، ', array_map(fn ($c) => $cols[$c] ?? $c, (array) $answer)), array_keys($value), array_values($value)));
    }

    /** @param  array<string,mixed>  $f  @param  list<array<string,mixed>>  $value */
    private function repeaterText(array $f, array $value): string
    {
        $subs = array_column($f['repeater']['subfields'], null, 'key');

        return implode("\n", array_map(fn ($row, $i) => ($i + 1).') '.implode('، ', array_map(function ($k, $v) use ($subs) {
            $labels = array_column($subs[$k]['options'] ?? [], 'label', 'value');

            return ($subs[$k]['label'] ?? $k).': '.($labels[$v] ?? (is_scalar($v) ? (string) $v : ''));
        }, array_keys($row), array_values($row))), $value, array_keys($value)));
    }

    // ---------------------------------------------------------------- the rules

    /**
     * @param  array<string,mixed>  $field
     * @param  array{default_country?:string}  $options
     * @return array{0:mixed, 1:?string}
     */
    private function coerce(array $field, mixed $raw, array $options = []): array
    {
        $v = $field['validation'] ?? [];

        return match ($field['type']) {
            'text', 'hidden' => $this->text($raw, self::TEXT_MAX, $v),
            'textarea' => $this->text($raw, self::TEXTAREA_MAX, $v),
            'phone' => $this->phone($raw, $options['default_country'] ?? 'SA'),
            'email' => is_string($raw) && filter_var(trim($raw), FILTER_VALIDATE_EMAIL) && mb_strlen(trim($raw)) <= 190 ? [trim($raw), null] : [null, 'invalid_email'],
            'number' => $this->number($raw, $v),
            'select', 'radio' => $this->oneOf($field, $raw),
            'multi_select' => $this->manyOf($field, $raw),
            'date' => $this->date($raw),
            'time' => is_string($raw) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $raw) ? [$raw, null] : [null, 'invalid_time'],
            'matrix' => $this->matrix($field, $raw),
            'repeater' => $this->repeater($field, $raw, $options),
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

        if (! empty($v['pattern']) && @preg_match('/'.str_replace('/', '\/', (string) $v['pattern']).'/u', $text) !== 1) {
            return [null, 'invalid_pattern'];
        }

        return [$text, null];
    }

    /** Typed with its country (`{country, number}`) or as one international string: either way it leaves as E.164 or not at all. */
    private function phone(mixed $raw, string $defaultCountry): array
    {
        $country = $defaultCountry;

        if (is_array($raw)) {
            $country = is_string($raw['country'] ?? null) ? strtoupper($raw['country']) : $defaultCountry;
            $raw = $raw['number'] ?? null;
        }

        $e164 = is_string($raw) ? PhoneCountries::e164($raw, $country) : null;

        return $e164 !== null ? [$e164, null] : [null, 'invalid_phone'];
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

    /** One answer for each row: a column, or — when the matrix allows many — several. Anything not on the grid is refused. */
    private function matrix(array $field, mixed $raw): array
    {
        $m = $field['matrix'];
        $rows = array_column($m['rows'], 'value');
        $columns = array_column($m['columns'], 'value');

        if (! is_array($raw) || array_is_list($raw)) {
            return [null, 'invalid_matrix'];
        }

        $answers = [];

        foreach ($raw as $row => $answer) {
            $row = (string) $row;

            if (! in_array($row, $rows, true)) {
                return [null, 'invalid_matrix'];
            }

            if ($m['multiple']) {
                $given = is_array($answer) ? array_values(array_unique(array_map('strval', $answer))) : [];

                if ($given === []) {
                    continue;
                }

                if (array_diff($given, $columns) !== []) {
                    return [null, 'invalid_matrix'];
                }

                $answers[$row] = $given;
            } else {
                if ($this->isEmpty($answer)) {
                    continue;
                }

                if (! is_scalar($answer) || ! in_array((string) $answer, $columns, true)) {
                    return [null, 'invalid_matrix'];
                }

                $answers[$row] = (string) $answer;
            }
        }

        if ($m['require_all_rows'] && count($answers) < count($rows)) {
            return [null, 'matrix_incomplete'];
        }

        if (count($answers) < (int) $m['min_answers']) {
            return [null, 'matrix_incomplete'];
        }

        return $answers === [] ? [null, 'required'] : [$answers, null];
    }

    private function matrixIsEmpty(mixed $value): bool
    {
        // Something that is not even a grid is not "nothing": it is wrong, and coerce says so.
        return is_array($value) && array_filter($value, fn ($a) => ! $this->isEmpty($a)) === [];
    }

    private function repeaterIsEmpty(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && array_filter($value, fn ($row) => is_array($row) && array_filter($row, fn ($c) => ! $this->isEmpty($c)) !== []) === [];
    }

    /**
     * The people (or whatever) the visitor added: each row checked column by column, a row with nothing in it dropped, the count held
     * between the form's minimum and maximum. A refusal names the row and the column.
     *
     * @param  array{default_country?:string}  $options
     */
    private function repeater(array $field, mixed $raw, array $options): array
    {
        $r = $field['repeater'];

        if (! is_array($raw) || ! array_is_list($raw)) {
            return [null, 'invalid_repeater'];
        }

        if (count($raw) > $r['max_rows'] + 5) {
            return [null, 'too_many_rows'];
        }

        $rows = [];
        $errors = [];

        foreach ($raw as $i => $row) {
            if (! is_array($row) || array_filter($row, fn ($c) => ! $this->isEmpty($c)) === []) {
                continue;
            }

            $clean = [];

            foreach ($r['subfields'] as $s) {
                $cell = $row[$s['key']] ?? null;

                if ($this->isEmpty($cell)) {
                    if ($s['required']) {
                        $errors[count($rows).'.'.$s['key']] = 'required';
                    }

                    continue;
                }

                [$value, $error] = $this->coerce($s + ['validation' => []], $cell, $options);

                if ($error !== null) {
                    $errors[count($rows).'.'.$s['key']] = $error;
                } else {
                    $clean[$s['key']] = $value;
                }
            }

            $rows[] = $clean;
        }

        if ($errors !== []) {
            return [['__errors' => $errors], null];
        }

        if (count($rows) > $r['max_rows']) {
            return [null, 'too_many_rows'];
        }

        if (count($rows) < $r['min_rows']) {
            return [null, 'too_few_rows'];
        }

        return $rows === [] ? [null, 'required'] : [$rows, null];
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
        return array_values(array_filter(array_map(fn ($o) => is_array($o) && isset($o['value']) ? ['value' => (string) $o['value'], 'label' => mb_substr(trim(strip_tags((string) ($o['label'] ?? $o['value']))), 0, 160)] : null, (array) ($field['options'] ?? []))));
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
