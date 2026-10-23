<?php

namespace App\Domain\Tracking\Forms;

/**
 * The fields a real estate form usually asks, ready to drop in: each with its type, its label in the workspace's
 * language, whether it is required, and where its answer lands. A starting point — every part is editable.
 */
class FieldPresets
{
    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        $field = fn (string $key, string $type, string $label, string $mapping, array $extra = []) => $extra + [
            'key' => $key, 'type' => $type, 'label' => $label, 'mapping' => $mapping, 'required' => false, 'hidden' => false,
        ];

        return [
            $field('name', 'text', 'الاسم', 'lead.full_name', ['required' => true]),
            $field('mobile', 'phone', 'رقم الجوال', 'lead.phone', ['required' => true]),
            $field('email', 'email', 'البريد الإلكتروني', 'lead.email'),
            $field('city', 'text', 'المدينة', 'lead.city'),
            $field('district', 'text', 'الحي المفضّل', 'lead.preferred_district'),
            $field('budget', 'number', 'الميزانية التقريبية', 'lead.budget_max', ['validation' => ['min' => 0]]),
            $field('property_type', 'select', 'نوع العقار', 'lead.preferred_property_type', ['options' => [['value' => 'villa', 'label' => 'فيلا'], ['value' => 'apartment', 'label' => 'شقة'], ['value' => 'townhouse', 'label' => 'تاون هاوس'], ['value' => 'land', 'label' => 'أرض']]]),
            $field('purpose', 'radio', 'الغرض من الشراء', 'lead.purchase_purpose', ['options' => [['value' => 'residence', 'label' => 'سكن'], ['value' => 'investment', 'label' => 'استثمار']]]),
            $field('financing', 'select', 'طريقة الدفع', 'lead.payment_method', ['options' => [['value' => 'cash', 'label' => 'كاش'], ['value' => 'mortgage', 'label' => 'تمويل عقاري'], ['value' => 'undecided', 'label' => 'لم أقرر']]]),
            $field('timeline', 'select', 'متى تنوي الشراء؟', 'lead.purchase_timeline', ['options' => [['value' => 'now', 'label' => 'خلال شهر'], ['value' => 'quarter', 'label' => 'خلال 3 أشهر'], ['value' => 'year', 'label' => 'خلال سنة'], ['value' => 'exploring', 'label' => 'أستكشف فقط']]]),
            // What the server knows about the visit: never typed by the visitor, never trusted from the browser.
            $field('project', 'hidden', 'المشروع', 'tracking.project', ['hidden' => true, 'default' => '@project']),
            $field('unit', 'hidden', 'الوحدة', 'tracking.unit', ['hidden' => true, 'default' => '@unit']),
            $field('source', 'hidden', 'المصدر', 'tracking.source', ['hidden' => true, 'default' => '@source']),
        ];
    }
}
