<?php

namespace Tests\Feature;

use App\Notifications\Concerns\MailsByPreference;
use Tests\TestCase;

/**
 * The notification emails say what happened, why it matters and what to do next, in one button.
 * These render the real templates; nothing here touches the database.
 */
class BrandedEmailTemplatesTest extends TestCase
{
    public function test_a_lead_alert_carries_the_facts_and_the_ways_to_reach_the_lead(): void
    {
        $html = view('emails.alert', [
            'title' => 'عميل محتمل جديد',
            'body' => 'خالد العتيبي ينتظر أول تواصل.',
            'severity' => 'critical',
            'pill' => MailsByPreference::pillFor('critical'),
            'link' => 'https://mohtam.pro/leads/abc',
            'workspace' => 'شركة الأفق',
            'facts' => ['المصدر' => 'سناب شات', 'الميزانية' => '1,200,000 – 1,500,000 ريال'],
            'whatsapp' => 'https://wa.me/966500000000',
            'call' => 'tel:+966500000000',
        ])->render();

        $this->assertStringContainsString('يحتاج تحركاً الآن', $html);
        $this->assertStringContainsString('سناب شات', $html);
        $this->assertStringContainsString('https://wa.me/966500000000', $html);
        $this->assertStringContainsString('tel:+966500000000', $html);
        $this->assertStringContainsString('https://mohtam.pro/leads/abc', $html);
        $this->assertStringContainsString('#b91c1c', $html, 'an urgent alert has the red button');
    }

    public function test_an_alert_without_a_lead_has_no_empty_boxes_or_dead_buttons(): void
    {
        $html = view('emails.alert', [
            'title' => 'منصة توقفت عن الإرسال',
            'body' => 'سناب شات لم يرسل شيئاً منذ يومين.',
            'severity' => 'warning',
            'pill' => MailsByPreference::pillFor('warning'),
            'link' => 'https://mohtam.pro/settings',
        ])->render();

        $this->assertStringNotContainsString('wa.me', $html);
        $this->assertStringNotContainsString('tel:', $html);
        $this->assertStringNotContainsString('border:1px solid #e5e7eb;border-radius:12px', $html);
        $this->assertStringContainsString('افتح في مهتم', $html);
    }

    public function test_the_digest_marks_the_top_item_and_tints_a_bad_tile(): void
    {
        $html = view('emails.digest', [
            'digest' => [
                'title' => 'ملخص أمس',
                'stats' => [['label' => 'بلا تواصل', 'value' => 7, 'tone' => 'bad'], ['label' => 'عملاء جدد', 'value' => 24]],
                'lists' => [['title' => 'يحتاج قرارك الآن', 'items' => ['سارة — ٤٢ دقيقة', 'حملة سناب'], 'links' => ['/leads/1', '/campaigns']]],
            ],
            'link' => 'https://mohtam.pro',
            'workspace' => 'شركة الأفق',
        ])->render();

        $this->assertStringContainsString('#FDE2E2', $html);
        $this->assertStringContainsString('https://mohtam.pro/leads/1', $html);
        $this->assertStringContainsString('#D9A441', $html);
    }

    public function test_the_layout_hides_a_preheader_and_shows_a_test_notice_only_when_asked(): void
    {
        $with = view('emails.alert', ['title' => 't', 'body' => 'نص المعاينة', 'severity' => 'info', 'link' => 'x'])->render();
        $this->assertStringContainsString('display:none', $with);
        $this->assertStringNotContainsString('رسالة اختبار', $with);
    }
}
