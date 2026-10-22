<?php

namespace App\Support;

/**
 * The real estate vocabulary, as stable keys with Arabic labels.
 *
 * Properties, leads and reports all speak it, so "villa" means one thing in the unit list, in
 * what the customer asked for, and in the matching engine that compares the two.
 */
class RealEstate
{
    public const PROPERTY_TYPES = ['apartment', 'villa', 'townhouse', 'duplex', 'land', 'office', 'shop', 'other'];

    /** What a property is offered for. */
    public const PROPERTY_TRANSACTIONS = ['sale', 'rent'];

    /** What a lead wants to do. */
    public const LEAD_TRANSACTIONS = ['buy', 'rent'];

    public const PROPERTY_STATUSES = ['available', 'reserved', 'sold', 'rented', 'hidden'];

    public const CHANNELS = ['whatsapp', 'call', 'email'];

    public const LABELS = [
        'property_type' => [
            'apartment' => 'شقة', 'villa' => 'فيلا', 'townhouse' => 'تاون هاوس', 'duplex' => 'دوبلكس',
            'land' => 'أرض', 'office' => 'مكتب', 'shop' => 'محل', 'other' => 'أخرى',
        ],
        'transaction_type' => ['buy' => 'شراء', 'rent' => 'إيجار', 'sale' => 'بيع'],
        'property_status' => ['available' => 'متاح', 'reserved' => 'محجوز', 'sold' => 'مباع', 'rented' => 'مؤجر', 'hidden' => 'مخفي'],
        'preferred_channel' => ['whatsapp' => 'واتساب', 'call' => 'اتصال', 'email' => 'بريد'],
    ];

    /** The kinds a lead's free text maps onto: what a customer calls a property → its key. */
    public static function typeFromWords(?string $text): ?string
    {
        $text = mb_strtolower(trim((string) $text));

        if ($text === '') {
            return null;
        }

        $words = [
            'villa' => ['فيلا', 'فلة', 'فله', 'villa'],
            'townhouse' => ['تاون', 'town'],
            'duplex' => ['دوبلكس', 'دبلكس', 'duplex'],
            'apartment' => ['شقة', 'شقه', 'apartment', 'flat'],
            'land' => ['أرض', 'ارض', 'land'],
            'office' => ['مكتب', 'office'],
            'shop' => ['محل', 'معرض', 'shop'],
        ];

        foreach ($words as $type => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return $type;
                }
            }
        }

        return null;
    }

    /** @return array<string, array<int, array{value:string,label:string}>> */
    public static function options(): array
    {
        $out = [];

        foreach (self::LABELS as $field => $labels) {
            $out[$field] = array_map(fn (string $v, string $l) => ['value' => $v, 'label' => $l], array_keys($labels), array_values($labels));
        }

        return $out;
    }
}
