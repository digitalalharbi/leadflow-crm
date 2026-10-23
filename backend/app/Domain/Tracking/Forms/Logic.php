<?php

namespace App\Domain\Tracking\Forms;

/**
 * A condition on a form, as data: `{match: all|any, conditions: [{field, op, value?}]}`.
 *
 * The same rule decides whether a field, a row or a section is shown, hidden or required, on the page and on the server — and
 * the server runs it again on every submission, because a page is only a suggestion. Pure, and pinned by the shared vectors
 * (`frontend/src/features/formVectors.json`) so the two implementations cannot drift apart.
 */
final class Logic
{
    public const OPS = ['equals', 'not_equals', 'contains', 'gt', 'gte', 'lt', 'lte', 'empty', 'not_empty'];

    public const MAX_CONDITIONS = 8;

    /** @param  array<string,mixed>|null  $group  @param  array<string,mixed>  $answers */
    public static function passes(?array $group, array $answers): bool
    {
        if ($group === null) {
            return true;
        }

        $conditions = (array) ($group['conditions'] ?? []);

        if ($conditions === []) {
            return true;
        }

        $results = array_map(fn (array $c) => self::holds($c, $answers), $conditions);

        return ($group['match'] ?? 'all') === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }

    /** @param  array<string,mixed>  $c  @param  array<string,mixed>  $answers */
    private static function holds(array $c, array $answers): bool
    {
        $answer = $answers[$c['field'] ?? ''] ?? null;
        $value = $c['value'] ?? null;

        return match ($c['op'] ?? '') {
            'equals' => self::same($answer, $value),
            'not_equals' => ! self::same($answer, $value),
            'contains' => self::contains($answer, $value),
            'gt' => self::compare($answer, $value, fn ($a, $b) => $a > $b),
            'gte' => self::compare($answer, $value, fn ($a, $b) => $a >= $b),
            'lt' => self::compare($answer, $value, fn ($a, $b) => $a < $b),
            'lte' => self::compare($answer, $value, fn ($a, $b) => $a <= $b),
            'empty' => self::isEmpty($answer),
            'not_empty' => ! self::isEmpty($answer),
            default => false,
        };
    }

    public static function isEmpty(mixed $v): bool
    {
        return $v === null || $v === '' || $v === [];
    }

    private static function same(mixed $answer, mixed $value): bool
    {
        if (self::isEmpty($answer)) {
            return false;
        }

        // A list of choices "equals" a value when it holds it: the question was "did they pick this?".
        if (is_array($answer)) {
            return in_array((string) $value, array_map(fn ($x) => is_scalar($x) ? (string) $x : '', array_values($answer)), true);
        }

        return is_scalar($value) && (string) $answer === (string) $value;
    }

    private static function contains(mixed $answer, mixed $value): bool
    {
        if (! is_scalar($value) || (string) $value === '' || self::isEmpty($answer)) {
            return false;
        }

        if (is_array($answer)) {
            return in_array((string) $value, array_map(fn ($x) => is_scalar($x) ? (string) $x : '', array_values($answer)), true);
        }

        return is_scalar($answer) && mb_strpos((string) $answer, (string) $value) !== false;
    }

    private static function compare(mixed $answer, mixed $value, callable $cmp): bool
    {
        if (is_bool($answer) || ! is_numeric($answer) || ! is_numeric($value)) {
            return false;
        }

        return (bool) $cmp($answer + 0, $value + 0);
    }

    /**
     * What is wrong with this group: an operator that does not exist, a field that is not on the form, a field waiting on itself.
     *
     * @param  array<string,mixed>|null  $group
     * @param  list<string>  $knownKeys
     * @return list<string>
     */
    public static function errors(?array $group, array $knownKeys, string $self): array
    {
        if ($group === null) {
            return [];
        }

        $errors = [];

        if (! in_array($group['match'] ?? 'all', ['all', 'any'], true)) {
            $errors[] = 'invalid_match';
        }

        $conditions = $group['conditions'] ?? [];

        if (! is_array($conditions) || count($conditions) > self::MAX_CONDITIONS) {
            return array_values(array_unique([...$errors, 'too_many_conditions']));
        }

        foreach ($conditions as $c) {
            if (! is_array($c) || ! in_array($c['op'] ?? null, self::OPS, true)) {
                $errors[] = 'invalid_operator';
            } elseif (($c['field'] ?? null) === $self) {
                $errors[] = 'self_reference';
            } elseif (! in_array($c['field'] ?? null, $knownKeys, true)) {
                $errors[] = 'unknown_field';
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * The group in its clean form, or null when there is nothing to wait for.
     *
     * @return array{match:string, conditions:list<array<string,mixed>>}|null
     */
    public static function clean(mixed $group): ?array
    {
        if (! is_array($group) || ! is_array($group['conditions'] ?? null) || $group['conditions'] === []) {
            return null;
        }

        $conditions = [];

        foreach (array_slice($group['conditions'], 0, self::MAX_CONDITIONS) as $c) {
            if (! is_array($c)) {
                continue;
            }

            $one = ['field' => (string) ($c['field'] ?? ''), 'op' => (string) ($c['op'] ?? '')];

            if (! in_array($one['op'], ['empty', 'not_empty'], true) && array_key_exists('value', $c)) {
                $one['value'] = is_scalar($c['value']) ? $c['value'] : null;
            }

            $conditions[] = $one;
        }

        return ['match' => ($group['match'] ?? 'all') === 'any' ? 'any' : 'all', 'conditions' => $conditions];
    }

    /**
     * The first generation of conditions — `{field, equals}`, `{field, in}`, `{field, not_empty}` — as a group.
     *
     * @param  array<string,mixed>  $c
     * @return array{match:string, conditions:list<array<string,mixed>>}
     */
    public static function fromLegacy(array $c): array
    {
        $field = (string) ($c['field'] ?? '');

        return match (true) {
            array_key_exists('equals', $c) => ['match' => 'all', 'conditions' => [['field' => $field, 'op' => 'equals', 'value' => $c['equals']]]],
            isset($c['in']) => ['match' => 'any', 'conditions' => array_map(fn ($v) => ['field' => $field, 'op' => 'equals', 'value' => $v], array_values((array) $c['in']))],
            ! empty($c['not_empty']) => ['match' => 'all', 'conditions' => [['field' => $field, 'op' => 'not_empty']]],
            default => ['match' => 'all', 'conditions' => []],
        };
    }
}
