<?php

namespace App\Domain\Mail;

use App\Models\Invitation;
use App\Models\PasswordLink;
use App\Models\Tenant;
use App\Support\TenantContext;

/**
 * The emails that open an account: an invitation, a first-password link, a reset link. Each carries a link, never a
 * password, and says how long the link lasts. Queued through the company's own delivery log (tenant-scoped), so the
 * company sees that it was sent and whether it arrived.
 */
class AccountEmail
{
    public function __construct(private EmailDeliveries $deliveries, private TenantContext $context) {}

    public function invitation(Invitation $invitation, string $token, Tenant $tenant, ?string $inviter): void
    {
        $company = $tenant->branding?->company_name ?? $tenant->name;

        $this->send($tenant, $invitation->email, $invitation->name, 'دعوة للانضمام إلى '.$company.' على مهتم', [
            'title' => 'دُعيت للانضمام إلى '.$company,
            'body' => ($inviter ? $inviter.' يدعوك' : 'تمت دعوتك').' للعمل على مهتم ضمن فريق '.$company.'. اضغط الزر لتختار كلمة مرورك وتبدأ.',
            'link' => $invitation->url($token),
            'button' => 'قبول الدعوة',
            'note' => 'الرابط صالح لمدة '.Invitation::DAYS.' أيام ويُستخدم مرة واحدة. إن لم تكن تتوقع هذه الدعوة فتجاهل الرسالة.',
            'workspace' => $company,
        ], 'invitation', $invitation->uuid.':'.$invitation->send_count);
    }

    public function passwordLink(string $email, ?string $name, string $purpose, string $token, Tenant $tenant): void
    {
        $company = $tenant->branding?->company_name ?? $tenant->name;
        $setup = $purpose === 'setup';

        $this->send($tenant, $email, $name, $setup ? 'جهّز حسابك على مهتم' : 'إعادة تعيين كلمة المرور في مهتم', [
            'title' => $setup ? 'مساحة عمل '.$company.' جاهزة' : 'طلب إعادة تعيين كلمة المرور',
            'body' => $setup ? 'أُنشئت مساحة عمل شركتك على مهتم وأنت مالكها. اختر كلمة مرورك لتدخل.' : 'وصلنا طلب لإعادة تعيين كلمة مرور حسابك. اضغط الزر لتختار كلمة مرور جديدة.',
            'link' => PasswordLink::url($token),
            'button' => $setup ? 'اختيار كلمة المرور' : 'تعيين كلمة مرور جديدة',
            'note' => $setup ? 'الرابط صالح لسبعة أيام ويُستخدم مرة واحدة.' : 'الرابط صالح لساعة واحدة ويُستخدم مرة واحدة. إن لم تطلب ذلك فتجاهل الرسالة؛ كلمة مرورك لم تتغير.',
            'workspace' => $company,
        ], 'account', null);
    }

    private function send(Tenant $tenant, string $email, ?string $name, string $subject, array $view, string $kind, ?string $key): void
    {
        $html = view('emails.account-link', $view + ['footer' => 'مهتم — رسالة آلية، لا ترد عليها.'])->render();

        $this->context->withTenant($tenant, fn () => $this->deliveries->queue(
            new OutboundEmail($email, $name, $subject, $html, idempotencyKey: $key ? substr(hash('sha256', $key), 0, 64) : ''),
            ['kind' => $kind],
        ));
    }
}
