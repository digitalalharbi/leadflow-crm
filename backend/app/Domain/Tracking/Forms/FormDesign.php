<?php

namespace App\Domain\Tracking\Forms;

/**
 * How a form looks and what it does around the questions — only ever chosen from a closed list.
 *
 * There is no free CSS, no HTML, no colour string: a header layout, a radius, a spacing, a colour *token*. The page maps each choice to
 * a style it already owns, so no manager (and nobody who reaches a manager's session) can put code or a stylesheet on a page that
 * strangers open from an ad. Texts are plain text; addresses are `https` or a logo this product itself stored.
 */
final class FormDesign
{
    public const HEADER_LAYOUTS = ['minimal', 'centered', 'split', 'cover', 'project_card'];

    public const SIZES = ['sm', 'md', 'lg'];

    public const ALIGN = ['start', 'center', 'end'];

    public const BACKGROUNDS = ['none', 'soft', 'brand', 'image'];

    public const SURFACES = ['card', 'flat'];

    public const RADII = ['none', 'sm', 'md', 'lg', 'xl'];

    public const SPACING = ['tight', 'normal', 'loose'];

    public const INPUT_SIZES = ['compact', 'comfortable', 'large'];

    public const BUTTONS = ['full', 'auto'];

    public const THEMES = ['light', 'brand', 'neutral'];

    /** The colours a form may be given. The page owns what each one is; the manager only picks a name. */
    public const PALETTE = ['emerald', 'teal', 'blue', 'indigo', 'violet', 'rose', 'amber', 'slate', 'stone'];

    public const MODES = ['single', 'multi'];

    public const ACTIONS = ['whatsapp', 'call', 'appointment', 'project', 'brochure'];

    /** What a form's own page may link to for its logo: this product's upload route, or an https address. */
    public const ASSET_PATH = '#^/api/v1/public/form-assets/[a-f0-9]{32}\.(png|jpg|webp)$#';

    public const MAX_SIGNALS = 12;

    public const MAX_SIGNAL_POINTS = 30;

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'header' => ['layout' => 'centered', 'show_logo' => true, 'logo_size' => 'md', 'logo_align' => 'center', 'show_project_name' => true, 'background' => 'none', 'location' => null, 'price_from' => null, 'trust_note' => null],
            'style' => ['surface' => 'card', 'radius' => 'lg', 'field_spacing' => 'normal', 'section_spacing' => 'normal', 'input_size' => 'comfortable', 'button' => 'full', 'theme' => 'light', 'primary' => 'emerald', 'accent' => 'amber'],
        ];
    }

    /** @return array<string,mixed> */
    public static function settingsDefaults(): array
    {
        return ['mode' => 'single', 'default_country' => 'SA', 'thanks' => ['actions' => [], 'redirect_url' => null, 'redirect_delay' => 5], 'gtm' => ['events' => true], 'signals' => [], 'variant' => ['key' => 'A', 'group' => null]];
    }

    /**
     * What is wrong with the design, the settings and the addresses, keyed by where (`design.style.primary`, `settings.thanks`…).
     *
     * @param  array<string,mixed>  $design
     * @param  array<string,mixed>  $settings
     * @param  list<string>  $fieldKeys
     * @return array<string,string>
     */
    public static function errors(array $design, array $settings, array $fieldKeys): array
    {
        $errors = [];
        $h = (array) ($design['header'] ?? []);
        $s = (array) ($design['style'] ?? []);

        $oneOf = function (string $path, mixed $value, array $allowed) use (&$errors) {
            if ($value !== null && ! in_array($value, $allowed, true)) {
                $errors[$path] = 'invalid_option';
            }
        };

        $oneOf('design.header.layout', $h['layout'] ?? null, self::HEADER_LAYOUTS);
        $oneOf('design.header.logo_size', $h['logo_size'] ?? null, self::SIZES);
        $oneOf('design.header.logo_align', $h['logo_align'] ?? null, self::ALIGN);
        $oneOf('design.header.background', $h['background'] ?? null, self::BACKGROUNDS);
        $oneOf('design.style.surface', $s['surface'] ?? null, self::SURFACES);
        $oneOf('design.style.radius', $s['radius'] ?? null, self::RADII);
        $oneOf('design.style.field_spacing', $s['field_spacing'] ?? null, self::SPACING);
        $oneOf('design.style.section_spacing', $s['section_spacing'] ?? null, self::SPACING);
        $oneOf('design.style.input_size', $s['input_size'] ?? null, self::INPUT_SIZES);
        $oneOf('design.style.button', $s['button'] ?? null, self::BUTTONS);
        $oneOf('design.style.theme', $s['theme'] ?? null, self::THEMES);
        $oneOf('design.style.primary', $s['primary'] ?? null, self::PALETTE);
        $oneOf('design.style.accent', $s['accent'] ?? null, self::PALETTE);

        if (isset($h['price_from']) && $h['price_from'] !== '' && (! is_numeric($h['price_from']) || $h['price_from'] < 0)) {
            $errors['design.header.price_from'] = 'invalid_number';
        }

        $oneOf('settings.mode', $settings['mode'] ?? null, self::MODES);

        if (isset($settings['default_country']) && ! PhoneCountries::has((string) $settings['default_country'])) {
            $errors['settings.default_country'] = 'invalid_option';
        }

        $thanks = (array) ($settings['thanks'] ?? []);
        $actions = (array) ($thanks['actions'] ?? []);

        if (count($actions) > 4) {
            $errors['settings.thanks'] = 'too_many_actions';
        }

        foreach ($actions as $a) {
            if (! is_array($a) || ! in_array($a['type'] ?? null, self::ACTIONS, true) || trim((string) ($a['label'] ?? '')) === '' || ! self::actionValueOk((string) $a['type'], (string) ($a['value'] ?? ''))) {
                $errors['settings.thanks'] = 'invalid_action';
            }
        }

        if (filled($thanks['redirect_url'] ?? null) && ! self::https((string) $thanks['redirect_url'])) {
            $errors['settings.thanks'] = 'invalid_redirect';
        }

        if (isset($thanks['redirect_delay']) && (! is_numeric($thanks['redirect_delay']) || $thanks['redirect_delay'] < 0 || $thanks['redirect_delay'] > 30)) {
            $errors['settings.thanks'] = 'invalid_redirect';
        }

        $signals = (array) ($settings['signals'] ?? []);

        if (count($signals) > self::MAX_SIGNALS) {
            $errors['settings.signals'] = 'too_many_signals';
        }

        foreach ($signals as $sig) {
            $points = $sig['points'] ?? null;

            if (! is_array($sig) || ! preg_match('/^[a-z][a-z0-9_]{0,30}$/', (string) ($sig['id'] ?? '')) || trim((string) ($sig['label'] ?? '')) === ''
                || ! is_numeric($points) || $points < 1 || $points > self::MAX_SIGNAL_POINTS
                || Logic::clean($sig['when'] ?? null) === null || Logic::errors($sig['when'], $fieldKeys, '') !== []) {
                $errors['settings.signals'] = 'invalid_signal';
            }
        }

        return $errors;
    }

    /** @param  array<string,mixed>  $design  @return array<string,mixed> */
    public static function normalizeDesign(array $design): array
    {
        $d = self::defaults();
        $h = (array) ($design['header'] ?? []);
        $s = (array) ($design['style'] ?? []);

        foreach (['layout', 'logo_size', 'logo_align', 'background'] as $k) {
            if (isset($h[$k])) {
                $d['header'][$k] = $h[$k];
            }
        }

        foreach (['show_logo', 'show_project_name'] as $k) {
            if (array_key_exists($k, $h)) {
                $d['header'][$k] = (bool) $h[$k];
            }
        }

        foreach (['location', 'trust_note'] as $k) {
            $d['header'][$k] = isset($h[$k]) ? self::text($h[$k], $k === 'trust_note' ? 200 : 120) : null;
        }

        $d['header']['price_from'] = isset($h['price_from']) && $h['price_from'] !== '' && is_numeric($h['price_from']) ? $h['price_from'] + 0 : null;

        foreach (array_keys($d['style']) as $k) {
            if (isset($s[$k])) {
                $d['style'][$k] = $s[$k];
            }
        }

        return $d;
    }

    /**
     * @param  array<string,mixed>  $settings
     * @return array<string,mixed>
     */
    public static function normalizeSettings(array $settings, int $steps = 1): array
    {
        $d = self::settingsDefaults();
        $d['mode'] = $settings['mode'] ?? ($steps > 1 ? 'multi' : 'single');
        $d['default_country'] = $settings['default_country'] ?? 'SA';

        $thanks = (array) ($settings['thanks'] ?? []);
        $d['thanks'] = [
            'actions' => array_values(array_map(fn ($a) => ['type' => $a['type'], 'label' => (string) self::text($a['label'], 60), 'value' => trim((string) $a['value'])], array_filter((array) ($thanks['actions'] ?? []), 'is_array'))),
            'redirect_url' => filled($thanks['redirect_url'] ?? null) ? trim((string) $thanks['redirect_url']) : null,
            'redirect_delay' => (int) ($thanks['redirect_delay'] ?? 5),
        ];
        $d['gtm'] = ['events' => (bool) (($settings['gtm']['events'] ?? true))];
        $d['signals'] = array_values(array_map(fn ($s) => ['id' => (string) $s['id'], 'label' => (string) self::text($s['label'], 80), 'points' => (int) $s['points'], 'when' => Logic::clean($s['when'])], array_filter((array) ($settings['signals'] ?? []), 'is_array')));
        $variant = (array) ($settings['variant'] ?? []);
        $d['variant'] = ['key' => preg_match('/^[A-Z]$/', (string) ($variant['key'] ?? '')) ? $variant['key'] : 'A', 'group' => preg_match('/^[a-z][a-z0-9_]{0,30}$/', (string) ($variant['group'] ?? '')) ? $variant['group'] : null];

        return $d;
    }

    /**
     * What the visitor's page may be told of the settings: how to behave, never how a lead is scored.
     *
     * @param  array<string,mixed>  $settings  normalised
     * @return array<string,mixed>
     */
    public static function publicSettings(array $settings): array
    {
        return ['mode' => $settings['mode'], 'default_country' => $settings['default_country'], 'thanks' => $settings['thanks'], 'gtm' => $settings['gtm']];
    }

    public static function https(string $url): bool
    {
        return (bool) preg_match('#^https://[^\s<>"\']+$#i', $url) && mb_strlen($url) <= 500;
    }

    /** A logo or hero: this product's own upload, or an https address. */
    public static function imageOk(?string $url): bool
    {
        return $url === null || $url === '' || self::https($url) || (bool) preg_match(self::ASSET_PATH, $url);
    }

    private static function actionValueOk(string $type, string $value): bool
    {
        return match ($type) {
            'whatsapp', 'call' => (bool) preg_match('/^\+?[0-9]{7,15}$/', preg_replace('/[\s\-().]/', '', $value) ?? ''),
            default => self::https($value),
        };
    }

    private static function text(mixed $v, int $max): ?string
    {
        $t = is_scalar($v) ? trim(strip_tags((string) $v)) : '';

        return $t === '' ? null : mb_substr($t, 0, $max);
    }
}
