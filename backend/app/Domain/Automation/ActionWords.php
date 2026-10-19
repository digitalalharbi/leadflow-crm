<?php

namespace App\Domain\Automation;

/**
 * An action in three or four words — "واتساب: ترحيب", "تقييم +20", "تذكير بعد
 * 15 د" — for the history, a recommendation and the rules table. A sentence
 * would be clearer in isolation and unreadable in a list of forty.
 */
class ActionWords
{
    public static function for(array $action): string
    {
        $purpose = fn () => match ($action['purpose'] ?? null) {
            'welcome' => 'ترحيب',
            'reminder' => 'تذكير',
            'nurture' => 'متابعة',
            'qualification' => 'أسئلة التأهيل',
            'appointment' => 'موعد',
            null => 'رسالة',
            default => (string) $action['purpose'],
        };

        return match ($action['type'] ?? null) {
            'send_whatsapp' => 'واتساب: '.$purpose(),
            'send_email' => 'بريد: '.$purpose(),
            'share_file' => 'إرسال البروشور',
            'ask_qualification' => 'أسئلة التأهيل',
            'adjust_score' => 'التقييم '.(((int) ($action['by'] ?? 0)) > 0 ? '+' : '').(int) ($action['by'] ?? 0),
            'set_priority' => 'أولوية',
            'set_temperature' => 'تصنيف: '.($action['temperature'] ?? ''),
            'change_stage' => 'المرحلة ← '.($action['status'] ?? ''),
            'assign' => 'إسناد لمندوب',
            'schedule_follow_up' => 'متابعة بعد '.((int) ($action['in_hours'] ?? 0)).' س',
            'create_call_task' => 'مهمة اتصال',
            'request_appointment' => 'طلب موعد',
            'notify' => 'تنبيه الفريق',
            'hand_off' => 'تسليم للمندوب',
            'stop_automation' => 'إيقاف الأتمتة',
            'start_nurture' => 'رعاية آلية',
            'add_tag' => 'وسم: '.($action['tag'] ?? ''),
            'add_note' => 'ملاحظة',
            'stop_follow_ups' => 'إيقاف المتابعات',
            default => (string) ($action['type'] ?? 'إجراء'),
        };
    }
}
