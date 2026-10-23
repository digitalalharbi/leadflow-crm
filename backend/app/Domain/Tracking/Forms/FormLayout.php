<?php

namespace App\Domain\Tracking\Forms;

/**
 * Where each field sits: sections, which hold rows, which hold up to four columns, which hold fields.
 *
 * The fields stay one flat list — the contract of what may be asked and where it lands — and the layout only places them, so a
 * form can be drawn a hundred ways without changing what a submission means. A section, a row and a field can each wait on
 * a condition; a hidden container hides everything in it. A form with no layout gets one made from its fields' steps.
 */
final class FormLayout
{
    public const MAX_SECTIONS = 10;

    public const MAX_ROWS = 30;

    public const MAX_COLUMNS = 4;

    public const DISPLAY = ['heading', 'paragraph', 'divider'];

    /**
     * The layout in its final form: every field placed exactly once, in order, each container with an id.
     *
     * @param  array<string,mixed>|null  $layout
     * @param  list<array<string,mixed>>  $fields  normalised
     * @return array{sections:list<array<string,mixed>>}
     */
    public function normalize(?array $layout, array $fields): array
    {
        $known = array_column($fields, 'key');
        $placed = [];
        $sections = [];
        $n = 0;

        foreach (array_slice((array) ($layout['sections'] ?? []), 0, self::MAX_SECTIONS) as $s) {
            if (! is_array($s)) {
                continue;
            }

            $rows = [];

            foreach (array_slice((array) ($s['rows'] ?? []), 0, self::MAX_ROWS) as $r) {
                $columns = [];

                foreach (array_slice((array) ($r['columns'] ?? []), 0, self::MAX_COLUMNS) as $c) {
                    $keys = [];

                    foreach ((array) ($c['fields'] ?? []) as $key) {
                        if (is_string($key) && in_array($key, $known, true) && ! isset($placed[$key])) {
                            $placed[$key] = true;
                            $keys[] = $key;
                        }
                    }

                    $columns[] = ['id' => $this->id($c['id'] ?? null, 'c'.(count($columns) + 1)), 'fields' => $keys];
                }

                // A row with nothing in it is only a gap: it goes.
                if (array_filter(array_column($columns, 'fields')) === []) {
                    continue;
                }

                $rows[] = $this->conditions($r) + ['id' => $this->id($r['id'] ?? null, 'r'.(++$n)), 'columns' => $columns];
            }

            $sections[] = $this->conditions($s) + [
                'id' => $this->id($s['id'] ?? null, 's'.(count($sections) + 1)),
                'title' => $this->text($s['title'] ?? null, 160),
                'description' => $this->text($s['description'] ?? null, 400),
                'step_title' => $this->text($s['step_title'] ?? null, 100),
                'rows' => $rows,
            ];
        }

        // What nobody placed is not lost: it is asked at the end, one to a row.
        $loose = array_values(array_filter($known, fn ($k) => ! isset($placed[$k])));

        if ($loose !== [] && $sections === []) {
            return $this->derive($fields);
        }

        if ($loose !== []) {
            $last = count($sections) - 1;

            foreach ($loose as $key) {
                $sections[$last]['rows'][] = ['id' => 'r'.(++$n), 'show_when' => null, 'hide_when' => null, 'columns' => [['id' => 'c1', 'fields' => [$key]]]];
            }
        }

        return ['sections' => $sections];
    }

    /**
     * A layout for a form that has none: a section for each step, a row for each field.
     *
     * @param  list<array<string,mixed>>  $fields  normalised
     * @return array{sections:list<array<string,mixed>>}
     */
    public function derive(array $fields): array
    {
        $sections = [];
        $n = 0;

        foreach (collect($fields)->groupBy(fn ($f) => max(1, (int) ($f['step'] ?? 1)))->sortKeys() as $step => $group) {
            $sections[] = [
                'id' => 's'.$step, 'title' => null, 'description' => null, 'step_title' => null, 'show_when' => null, 'hide_when' => null,
                'rows' => $group->map(fn ($f) => ['id' => 'r'.(++$n), 'show_when' => null, 'hide_when' => null, 'columns' => [['id' => 'c1', 'fields' => [$f['key']]]]])->values()->all(),
            ];
        }

        return ['sections' => $sections];
    }

    /**
     * What is wrong with the layout, by code.
     *
     * @param  array<string,mixed>|null  $layout  as the manager sent it
     * @param  list<array<string,mixed>>  $fields  normalised
     * @return list<string>
     */
    public function errors(?array $layout, array $fields): array
    {
        if ($layout === null) {
            return [];
        }

        $known = array_column($fields, 'key');
        $errors = [];
        $seen = [];
        $sections = (array) ($layout['sections'] ?? []);

        if (count($sections) > self::MAX_SECTIONS) {
            $errors[] = 'layout_too_many_sections';
        }

        foreach ($sections as $s) {
            foreach (['show_when', 'hide_when'] as $w) {
                $errors = [...$errors, ...array_map(fn ($c) => "layout_{$c}", Logic::errors(is_array($s[$w] ?? null) ? $s[$w] : null, $known, ''))];
            }

            if (count((array) ($s['rows'] ?? [])) > self::MAX_ROWS) {
                $errors[] = 'layout_too_many_rows';
            }

            foreach ((array) ($s['rows'] ?? []) as $r) {
                foreach (['show_when', 'hide_when'] as $w) {
                    $errors = [...$errors, ...array_map(fn ($c) => "layout_{$c}", Logic::errors(is_array($r[$w] ?? null) ? $r[$w] : null, $known, ''))];
                }

                $columns = (array) ($r['columns'] ?? []);

                if (count($columns) > self::MAX_COLUMNS || $columns === []) {
                    $errors[] = 'layout_columns';
                }

                foreach ($columns as $c) {
                    foreach ((array) ($c['fields'] ?? []) as $key) {
                        if (! in_array($key, $known, true)) {
                            $errors[] = 'layout_unknown_field';
                        } elseif (isset($seen[$key])) {
                            $errors[] = 'layout_duplicate_field';
                        }

                        $seen[$key] = true;
                    }
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * The sections a multi-step form walks through, in order.
     *
     * @param  array{sections:list<array<string,mixed>>}  $layout
     */
    public function stepCount(array $layout): int
    {
        return max(1, count($layout['sections']));
    }

    /**
     * What is shown and what is required, for these answers. A hidden field's answer does not count, so a field that waits on one
     * goes too — repeated until nothing changes (a field may wait on one further down the page).
     *
     * @param  list<array<string,mixed>>  $fields  normalised
     * @param  array{sections:list<array<string,mixed>>}  $layout  normalised
     * @param  array<string,mixed>  $answers
     * @return array{visible:list<string>, required:list<string>}
     */
    public function visibility(array $fields, array $layout, array $answers): array
    {
        $byKey = [];

        foreach ($fields as $f) {
            $byKey[$f['key']] = $f;
        }

        $asked = array_keys(array_filter($byKey, fn ($f) => ! $f['hidden']));
        $visible = $asked;

        for ($pass = 0; $pass < 8; $pass++) {
            $effective = array_intersect_key($answers, array_flip($visible));
            $next = [];

            foreach ($layout['sections'] as $s) {
                $sectionShown = $this->shows($s, $effective);

                foreach ($s['rows'] as $r) {
                    $rowShown = $sectionShown && $this->shows($r, $effective);

                    foreach ($r['columns'] as $c) {
                        foreach ($c['fields'] as $key) {
                            if ($rowShown && isset($byKey[$key]) && ! $byKey[$key]['hidden'] && $this->shows($byKey[$key], $effective)) {
                                $next[] = $key;
                            }
                        }
                    }
                }
            }

            $stable = $this->sameSet($next, $visible);
            $visible = $next;

            if ($stable) {
                break;
            }
        }

        $effective = array_intersect_key($answers, array_flip($visible));
        $required = array_values(array_filter($visible, fn ($k) => ! in_array($byKey[$k]['type'], self::DISPLAY, true)
            && ($byKey[$k]['required'] || (($byKey[$k]['require_when'] ?? null) !== null && Logic::passes($byKey[$k]['require_when'], $effective)))));

        return ['visible' => $visible, 'required' => $required];
    }

    /** @param  array<string,mixed>  $thing  a section, a row or a field  @param  array<string,mixed>  $effective */
    private function shows(array $thing, array $effective): bool
    {
        $show = $thing['show_when'] ?? null;
        $hide = $thing['hide_when'] ?? null;

        return ($show === null || Logic::passes($show, $effective)) && ($hide === null || ! Logic::passes($hide, $effective));
    }

    /** @param  list<string>  $a  @param  list<string>  $b */
    private function sameSet(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }

    /** @return array{show_when:?array<string,mixed>, hide_when:?array<string,mixed>} */
    private function conditions(array $thing): array
    {
        return ['show_when' => Logic::clean($thing['show_when'] ?? null), 'hide_when' => Logic::clean($thing['hide_when'] ?? null)];
    }

    private function id(mixed $given, string $fallback): string
    {
        return is_string($given) && preg_match('/^[a-z][a-z0-9_-]{0,23}$/', $given) ? $given : $fallback;
    }

    private function text(mixed $v, int $max): ?string
    {
        $t = is_scalar($v) ? trim(strip_tags((string) $v)) : '';

        return $t === '' ? null : mb_substr($t, 0, $max);
    }
}
