<?php

namespace App\Domain\Tracking\Forms;

use App\Domain\Leads\LeadTags;

/**
 * What makes a form a campaign page: which template, the project's facts, the ways to act, what it tags a lead with, how a
 * search engine and a shared link see it. A closed list, on purpose: a manager chooses within a page that stays good, and
 * everything here is shown to strangers, so it is plain text, known keys and checked numbers.
 */
final class LandingPageConfig
{
    public const TEMPLATES = ['real_estate_lead', 'project_showcase', 'minimal_lead'];

    public const FACTS = ['location', 'project_type', 'price_from', 'unit_types', 'delivery'];

    public const CTA_KINDS = ['form', 'whatsapp', 'phone'];

    /**
     * After a tap on WhatsApp nobody knows the visitor's number, so a lead cannot be made at the tap itself: `track` records
     * the click; `inbound` also gives the lead the page's credit when their first message arrives carrying its reference.
     */
    public const WHATSAPP_MODES = ['track', 'inbound'];

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'template' => 'real_estate_lead',
            'developer_name' => null,
            'developer_logo_url' => null,
            'facts' => [],
            'cta' => [
                'form' => ['enabled' => true],
                'whatsapp' => ['enabled' => false, 'number' => null, 'message' => null, 'mode' => 'inbound'],
                'phone' => ['enabled' => false, 'number' => null],
                'primary' => 'form',
                'secondary' => null,
                'floating_whatsapp' => false,
                'floating_call' => false,
            ],
            'tags' => [],
            'auto_steps' => true,
            'seo' => ['title' => null, 'description' => null, 'og_image' => null, 'index' => false],
            'mirror' => ['enabled' => false, 'spreadsheet_id' => null, 'tab' => null],
        ];
    }

    /** @return array<string,mixed> */
    public static function normalize(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $d = self::defaults();
        $cta = (array) ($raw['cta'] ?? []);
        $text = fn (mixed $v, int $max): ?string => is_scalar($v) && trim(strip_tags((string) $v)) !== '' ? mb_substr(trim(strip_tags((string) $v)), 0, $max) : null;
        $number = fn (mixed $v): ?string => is_scalar($v) && preg_match('/^\+?[0-9]{7,15}$/', (string) preg_replace('/[\s()-]/', '', (string) $v)) ? (string) preg_replace('/[\s()-]/', '', (string) $v) : null;

        $facts = [];
        foreach ((array) ($raw['facts'] ?? []) as $fact) {
            if (is_array($fact) && in_array($fact['key'] ?? null, self::FACTS, true) && ($value = $text($fact['value'] ?? null, 120)) !== null) {
                $facts[$fact['key']] = ['key' => $fact['key'], 'value' => $value, 'visible' => (bool) ($fact['visible'] ?? true)];
            }
        }

        $whatsapp = (array) ($cta['whatsapp'] ?? []);
        $phone = (array) ($cta['phone'] ?? []);
        $on = [
            'form' => (bool) (($cta['form'] ?? [])['enabled'] ?? true),
            'whatsapp' => (bool) ($whatsapp['enabled'] ?? false),
            'phone' => (bool) ($phone['enabled'] ?? false),
        ];
        $primary = in_array($cta['primary'] ?? null, self::CTA_KINDS, true) && $on[$cta['primary']] ? $cta['primary'] : (collect(self::CTA_KINDS)->first(fn ($k) => $on[$k]) ?? 'form');
        $secondary = in_array($cta['secondary'] ?? null, self::CTA_KINDS, true) && $on[$cta['secondary']] && $cta['secondary'] !== $primary ? $cta['secondary'] : null;

        $seo = (array) ($raw['seo'] ?? []);
        $mirror = (array) ($raw['mirror'] ?? []);
        $spreadsheet = is_string($mirror['spreadsheet_id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{20,128}$/', $mirror['spreadsheet_id']) ? $mirror['spreadsheet_id'] : null;

        return [
            'template' => in_array($raw['template'] ?? null, self::TEMPLATES, true) ? $raw['template'] : $d['template'],
            'developer_name' => $text($raw['developer_name'] ?? null, 120),
            'developer_logo_url' => FormDesign::imageOk($raw['developer_logo_url'] ?? null) ? ($raw['developer_logo_url'] ?? null) : null,
            'facts' => array_values($facts),
            'cta' => [
                'form' => ['enabled' => $on['form']],
                'whatsapp' => [
                    'enabled' => $on['whatsapp'],
                    'number' => $number($whatsapp['number'] ?? null),
                    'message' => $text($whatsapp['message'] ?? null, 300),
                    'mode' => in_array($whatsapp['mode'] ?? null, self::WHATSAPP_MODES, true) ? $whatsapp['mode'] : 'inbound',
                ],
                'phone' => ['enabled' => $on['phone'], 'number' => $number($phone['number'] ?? null)],
                'primary' => $primary,
                'secondary' => $secondary,
                'floating_whatsapp' => $on['whatsapp'] && (bool) ($cta['floating_whatsapp'] ?? false),
                'floating_call' => $on['phone'] && (bool) ($cta['floating_call'] ?? false),
            ],
            'tags' => array_slice(LeadTags::normalise((array) ($raw['tags'] ?? [])), 0, 5),
            'auto_steps' => (bool) ($raw['auto_steps'] ?? true),
            'seo' => [
                'title' => $text($seo['title'] ?? null, 70),
                'description' => $text($seo['description'] ?? null, 200),
                'og_image' => FormDesign::imageOk($seo['og_image'] ?? null) ? ($seo['og_image'] ?? null) : null,
                'index' => (bool) ($seo['index'] ?? false),
            ],
            'mirror' => ['enabled' => (bool) ($mirror['enabled'] ?? false) && $spreadsheet !== null, 'spreadsheet_id' => $spreadsheet, 'tab' => $text($mirror['tab'] ?? null, 100)],
        ];
    }

    /** @return array<string,string> what stops publishing, by path */
    public static function errors(array $page, bool $hasFields = true): array
    {
        $errors = [];
        $cta = $page['cta'];

        if ($cta['whatsapp']['enabled'] && $cta['whatsapp']['number'] === null) {
            $errors['page.cta.whatsapp.number'] = 'number_required';
        }

        if ($cta['phone']['enabled'] && $cta['phone']['number'] === null) {
            $errors['page.cta.phone.number'] = 'number_required';
        }

        if (! $cta['form']['enabled'] && ! $cta['whatsapp']['enabled'] && ! $cta['phone']['enabled']) {
            $errors['page.cta'] = 'no_way_to_act';
        }

        return $errors;
    }

    /** What the public page needs. Never the mirror's spreadsheet. @return array<string,mixed> */
    public static function publicShape(array $page): array
    {
        $shape = $page;
        unset($shape['mirror'], $shape['tags']);
        $shape['facts'] = array_values(array_map(fn ($f) => ['key' => $f['key'], 'value' => $f['value']], array_filter($page['facts'], fn ($f) => $f['visible'])));

        return $shape;
    }
}
