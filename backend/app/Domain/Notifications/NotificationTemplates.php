<?php

namespace App\Domain\Notifications;

use Illuminate\Support\Facades\Log;

/**
 * The words of the product's own alerts, in one place and in both languages, instead of in the job that sends them. A
 * template says which variables it cannot do without: missing one, nothing is sent, because a broken message is worse
 * than a late one, and the gap is logged. Customer-facing messages are not here: a company writes those itself.
 */
class NotificationTemplates
{
    /** key => [kind, severity, [variables it needs], cta, ar [title, body, cta], en [title, body, cta]] */
    public const TEMPLATES = [
        'lead_reassigned' => ['kind' => 'lead_reassigned', 'severity' => 'info', 'needs' => ['lead_name'],
            'ar' => ['أُسند إليك عميل', '{{lead_name}} أصبح مسؤوليتك الآن.', 'فتح العميل'],
            'en' => ['A lead was assigned to you', '{{lead_name}} is now yours.', 'Open the lead']],
        'task_due' => ['kind' => 'task_due', 'severity' => 'info', 'needs' => ['task_title'],
            'ar' => ['مهمة يحين وقتها', '{{task_title}}', 'فتح المهام'],
            'en' => ['A task is due', '{{task_title}}', 'Open tasks']],
        'task_overdue' => ['kind' => 'task_overdue', 'severity' => 'warning', 'needs' => ['task_title'],
            'ar' => ['مهمة متأخرة', '{{task_title}}', 'فتح المهام'],
            'en' => ['A task is overdue', '{{task_title}}', 'Open tasks']],
        'appointment_reminder' => ['kind' => 'appointment_reminder', 'severity' => 'info', 'needs' => ['lead_name', 'appointment_date', 'appointment_time'],
            'ar' => ['تذكير بموعد', 'موعد {{lead_name}} في {{appointment_date}} الساعة {{appointment_time}}.', 'فتح العميل'],
            'en' => ['Appointment reminder', '{{lead_name}} is booked for {{appointment_date}} at {{appointment_time}}.', 'Open the lead']],
        'appointment_changed' => ['kind' => 'appointment_changed', 'severity' => 'info', 'needs' => ['lead_name', 'appointment_date', 'appointment_time'],
            'ar' => ['تغيّر موعد', 'أصبح موعد {{lead_name}} في {{appointment_date}} الساعة {{appointment_time}}.', 'فتح العميل'],
            'en' => ['An appointment moved', '{{lead_name}} is now booked for {{appointment_date}} at {{appointment_time}}.', 'Open the lead']],
        'appointment_cancelled' => ['kind' => 'appointment_cancelled', 'severity' => 'info', 'needs' => ['lead_name'],
            'ar' => ['أُلغي موعد', 'أُلغي موعد {{lead_name}}.', 'فتح العميل'],
            'en' => ['An appointment was cancelled', 'The appointment with {{lead_name}} was cancelled.', 'Open the lead']],
        'visit_completed' => ['kind' => 'visit_completed', 'severity' => 'info', 'needs' => ['lead_name'],
            'ar' => ['تمت الزيارة', 'تمت زيارة {{lead_name}}. سجّل نتيجتها.', 'فتح العميل'],
            'en' => ['Visit done', 'The visit with {{lead_name}} took place. Record the outcome.', 'Open the lead']],
        'reservation_created' => ['kind' => 'reservation_created', 'severity' => 'info', 'needs' => ['lead_name'],
            'ar' => ['حجز جديد', 'حُجزت وحدة لـ{{lead_name}}.', 'فتح العميل'],
            'en' => ['New reservation', 'A unit was reserved for {{lead_name}}.', 'Open the lead']],
        'reservation_expiring' => ['kind' => 'reservation_expiring', 'severity' => 'warning', 'needs' => ['lead_name'],
            'ar' => ['حجز يوشك أن ينتهي', 'ينتهي حجز {{lead_name}} خلال 24 ساعة.', 'فتح العميل'],
            'en' => ['A reservation is about to lapse', 'The hold for {{lead_name}} ends within 24 hours.', 'Open the lead']],
        'reservation_cancelled' => ['kind' => 'reservation_cancelled', 'severity' => 'info', 'needs' => ['lead_name'],
            'ar' => ['أُلغي حجز', 'أُلغي حجز {{lead_name}}.', 'فتح العميل'],
            'en' => ['A reservation was cancelled', 'The hold for {{lead_name}} was cancelled.', 'Open the lead']],
        'sale_completed' => ['kind' => 'sale_completed', 'severity' => 'info', 'needs' => ['lead_name'],
            'ar' => ['صفقة مكتملة', 'تمّت الصفقة مع {{lead_name}}.', 'فتح العميل'],
            'en' => ['Sale completed', 'The sale with {{lead_name}} is done.', 'Open the lead']],
        'sheets_sync_failed' => ['kind' => 'sheet_sync_failed', 'severity' => 'warning', 'needs' => ['source_name'],
            'ar' => ['تعذرت مزامنة جدول Google', 'لم تُقرأ صفوف جديدة من «{{source_name}}».', 'فتح التكاملات'],
            'en' => ['A Google Sheet failed to sync', 'No new rows were read from "{{source_name}}".', 'Open integrations']],
        'provider_disconnected' => ['kind' => 'provider_disconnected', 'severity' => 'critical', 'needs' => ['provider_name'],
            'ar' => ['انقطع الاتصال بـ{{provider_name}}', 'يلزم إعادة الربط، وإلى ذلك الحين لا يعمل هذا التكامل.', 'فتح التكاملات'],
            'en' => ['{{provider_name}} is disconnected', 'It needs connecting again, and does not work until then.', 'Open integrations']],
        'webhook_failed' => ['kind' => 'webhook_failed', 'severity' => 'warning', 'needs' => ['webhook_name'],
            'ar' => ['أُوقف Webhook بعد فشل متكرر', 'توقف الإرسال إلى «{{webhook_name}}».', 'فتح التكاملات'],
            'en' => ['A webhook was paused after repeated failures', 'Sending to "{{webhook_name}}" has stopped.', 'Open integrations']],
        'sheet_mirror_failed' => ['kind' => 'sheet_mirror_failed', 'severity' => 'warning', 'needs' => [],
            'ar' => ['تعذرت كتابة عميل في جدول Google', 'العميل محفوظ في مهتم، لكنه لم يظهر في الجدول.', 'فتح التكاملات'],
            'en' => ['A lead could not be written to Google Sheets', 'The lead is safe in Mohtam, but is not in the sheet.', 'Open integrations']],
    ];

    /** @return array{kind:string, severity:string, title:string, body:string, cta:string}|null null: a variable it needs is missing */
    public static function render(string $key, ?string $locale, array $variables): ?array
    {
        $template = self::TEMPLATES[$key] ?? null;

        if ($template === null) {
            return null;
        }

        foreach ($template['needs'] as $needed) {
            if (blank($variables[$needed] ?? null)) {
                Log::warning('notification.template_incomplete', ['template' => $key, 'missing' => $needed]);

                return null;
            }
        }

        [$title, $body, $cta] = $template[$locale === 'en' ? 'en' : 'ar'];
        $fill = fn (string $text) => preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', fn ($m) => (string) ($variables[$m[1]] ?? ''), $text);

        return ['kind' => $template['kind'], 'severity' => $template['severity'], 'title' => $fill($title), 'body' => $fill($body), 'cta' => $cta];
    }
}
