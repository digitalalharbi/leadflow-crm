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
        ];
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
