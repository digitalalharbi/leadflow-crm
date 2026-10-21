<?php

namespace App\Domain\Mail;

use App\Models\ReportShare;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * A report, as an email: its name, a sentence from the sender, and the link — never the numbers.
 *
 * The numbers stay behind the link (with its password, its expiry and its revocation), so an email
 * that is forwarded, archived or leaked carries a door and not the room; the page behind it is live,
 * so what the reader opens is never older than their click. A password is never in the email.
 */
class ReportEmail
{
    /** @return array{html:string,subject:string} */
    public static function compose(ReportShare $share, string $recipient, ?string $message, ?User $from): array
    {
        $workspace = $share->tenant?->branding?->company_name ?? $share->tenant?->name;
        $subject = $share->name;
        $rtl = ($share->tenant?->settings?->locale ?? 'ar') !== 'en';

        $html = view('emails.report-share', [
            'title' => $share->name,
            'message' => $message ? Str::limit(trim($message), 1000, '') : null,
            'intro' => $rtl ? 'تقرير حيّ يُحدَّث عند كل فتح. اضغط للاطلاع عليه:' : 'A live report, up to date every time it is opened:',
            'period' => $rtl ? 'آخر '.$share->days.' يومًا' : 'last '.$share->days.' days',
            'link' => $share->url(),
            'button' => $rtl ? 'افتح التقرير' : 'Open the report',
            'protected' => $share->hasPassword(),
            'protectedNote' => $rtl ? 'هذا التقرير محميّ بكلمة مرور تصلك من المرسِل بطريق آخر.' : 'This report is protected by a password the sender gives you separately.',
            'footer' => $rtl ? 'أُرسلت هذه الرسالة نيابةً عن '.($from?->name ?? $workspace ?? 'مهتم').'.' : 'Sent on behalf of '.($from?->name ?? $workspace ?? 'Mohtam').'.',
            'workspace' => $workspace,
        ])->render();

        return ['subject' => $subject, 'html' => $html];
    }
}
