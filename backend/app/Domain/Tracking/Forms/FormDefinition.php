<?php

namespace App\Domain\Tracking\Forms;

use App\Models\LandingForm;

/**
 * A form as one frozen piece: everything the page draws and the server enforces. Built from the draft (to publish, to preview, to tell
 * whether the draft differs from what is live) and kept as a version's snapshot.
 */
final class FormDefinition
{
    /** The draft as a snapshot, every part normalised: the same input always gives the same checksum. */
    public static function snapshot(LandingForm $form): array
    {
        $schema = new FormSchema;
        $layouts = new FormLayout;
        $fields = $schema->normalize($form->fields ?? []);
        $layout = $layouts->normalize($form->layout, $fields);
        $steps = max(1, (int) collect($fields)->max('step'));
        $settings = FormDesign::normalizeSettings((array) $form->settings, $steps);

        // A page keeps a short form on one screen and turns a long one into steps by itself, unless its manager laid out their own.
        if ($form->page !== null && $settings['mode'] === 'single' && LandingPageConfig::normalize($form->page)['auto_steps']) {
            $split = self::autoSteps($layout, $fields);

            if ($split !== null) {
                $layout = $split;
                $settings['mode'] = 'multi';
            }
        }

        // In a multi-step form each section is a step: the fields learn which, so the old step-based screens keep working.
        $fields = self::withSteps($fields, $layout, $settings['mode']);

        return [
            'name' => $form->name,
            'headline' => $form->headline,
            'description' => $form->description,
            'benefits' => array_values((array) ($form->benefits ?? [])),
            'cta_label' => $form->cta_label,
            'logo_url' => $form->logo_url,
            'image_url' => $form->image_url,
            'thank_you_title' => $form->thank_you_title,
            'thank_you_message' => $form->thank_you_message,
            'consent_required' => (bool) $form->consent_required,
            'consent_text' => $form->consent_text,
            'privacy_url' => $form->privacy_url,
            'fields' => $fields,
            'layout' => $layout,
            'design' => FormDesign::normalizeDesign((array) $form->design),
            'settings' => $settings,
            // Only on a form that is a page: every form published before pages existed keeps its checksum.
        ] + ($form->page === null ? [] : ['page' => LandingPageConfig::normalize($form->page)]);
    }

    public static function checksum(array $snapshot): string
    {
        return sha1(json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /** How many steps the visitor walks through. */
    public static function steps(array $snapshot): int
    {
        return ($snapshot['settings']['mode'] ?? 'single') === 'multi' ? max(1, count($snapshot['layout']['sections'])) : 1;
    }

    /**
     * @param  list<array<string,mixed>>  $fields
     * @param  array{sections:list<array<string,mixed>>}  $layout
     * @return list<array<string,mixed>>
     */
    /** Up to this many questions fit on one screen without a page feeling like paperwork. */
    public const AUTO_STEP_AT = 5;

    /** @return array<string,mixed>|null the layout cut into steps of rows, or null when it should stay as it is */
    private static function autoSteps(array $layout, array $fields): ?array
    {
        $asked = array_column(array_filter($fields, fn (array $f) => empty($f['hidden']) && ! in_array($f['type'], [...FormSchema::DISPLAY, 'hidden', 'consent'], true)), 'key');

        if (count($asked) <= self::AUTO_STEP_AT || count($layout['sections']) !== 1) {
            return null;
        }

        $sections = [];
        $rows = [];
        $count = 0;

        foreach ($layout['sections'][0]['rows'] as $row) {
            $here = 0;

            foreach ($row['columns'] as $column) {
                $here += count(array_intersect($column['fields'], $asked));
            }

            if ($rows !== [] && $count + $here > 2) {
                $sections[] = $rows;
                $rows = [];
                $count = 0;
            }

            $rows[] = $row;
            $count += $here;
        }

        if ($rows !== []) {
            $sections[] = $rows;
        }

        $first = $layout['sections'][0];

        return ['sections' => array_map(fn (array $rows, int $i) => ['rows' => $rows, 'id' => ($first['id'] ?? 's').'-'.($i + 1)] + array_diff_key($first, ['rows' => 1, 'id' => 1]), $sections, array_keys($sections))] + $layout;
    }

    private static function withSteps(array $fields, array $layout, string $mode): array
    {
        $step = [];

        foreach ($layout['sections'] as $i => $s) {
            foreach ($s['rows'] as $r) {
                foreach ($r['columns'] as $c) {
                    foreach ($c['fields'] as $key) {
                        $step[$key] = $mode === 'multi' ? $i + 1 : 1;
                    }
                }
            }
        }

        return array_map(fn (array $f) => array_merge($f, ['step' => $step[$f['key']] ?? 1]), $fields);
    }
}
