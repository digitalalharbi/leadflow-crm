<?php

namespace App\Support;

/**
 * The qualification vocabulary, in one place.
 *
 * Stored as stable keys and shown in Arabic, so a tenant that later switches
 * language keeps its data, and a report can group on something that does not
 * change when a label is reworded.
 */
class Qualification
{
    public const PURPOSES = ['residence', 'investment', 'unknown'];

    public const PAYMENT_METHODS = ['cash', 'financing', 'unknown'];

    public const FINANCING_STATUSES = ['approved', 'in_progress', 'not_started', 'not_required', 'unknown'];

    public const TIMELINES = ['within_month', 'within_3_months', 'within_6_months', 'over_6_months', 'unknown'];

    public const TRANSACTIONS = ['buy', 'rent'];

    public const CHANNELS = ['whatsapp', 'call', 'email'];

    public const LABELS = [
        'purchase_purpose' => [
            'residence' => 'سكن',
            'investment' => 'استثمار',
            'unknown' => 'غير محدد',
        ],
        'payment_method' => [
            'cash' => 'كاش',
            'financing' => 'تمويل',
            'unknown' => 'غير محدد',
        ],
        'financing_status' => [
            'approved' => 'موافقة تمويلية',
            'in_progress' => 'تحت الإجراء',
            'not_started' => 'لم يبدأ',
            'not_required' => 'غير مطلوب',
            'unknown' => 'غير محدد',
        ],
        'transaction_type' => ['buy' => 'شراء', 'rent' => 'إيجار'],
        'preferred_channel' => ['whatsapp' => 'واتساب', 'call' => 'اتصال', 'email' => 'بريد'],
        'purchase_timeline' => [
            'within_month' => 'خلال شهر',
            'within_3_months' => 'من شهر إلى 3 أشهر',
            'within_6_months' => 'من 3 إلى 6 أشهر',
            'over_6_months' => 'أكثر من 6 أشهر',
            'unknown' => 'غير محدد',
        ],
    ];

    /** @return array<string, array<int, array{value: string, label: string}>> */
    public static function options(): array
    {
        $options = [];

        foreach (self::LABELS as $field => $labels) {
            $options[$field] = array_map(
                fn (string $value, string $label) => ['value' => $value, 'label' => $label],
                array_keys($labels),
                array_values($labels),
            );
        }

        return $options;
    }

    public static function label(string $field, ?string $value): ?string
    {
        return $value === null ? null : (self::LABELS[$field][$value] ?? $value);
    }
}
