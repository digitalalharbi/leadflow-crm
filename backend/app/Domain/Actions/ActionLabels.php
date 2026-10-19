<?php

namespace App\Domain\Actions;

/** The action names a person reads outside the app — in a WhatsApp message, on the approval page. */
final class ActionLabels
{
    private const AR = [
        'pause' => 'إيقاف', 'resume' => 'تشغيل', 'update_budget' => 'تعديل الميزانية', 'duplicate_ad_set' => 'تكرار المجموعة',
        'create_campaign' => 'حملة جديدة', 'create_ad' => 'إعلان جديد', 'update_creative' => 'تحديث الإعلان',
        'update_targeting' => 'تحديث الاستهداف', 'schedule' => 'جدولة',
    ];

    public static function ar(string $action): string
    {
        return self::AR[$action] ?? $action;
    }
}
