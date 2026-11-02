<?php

namespace App\Domain\Mail;

use App\Domain\Auth\EmailVerification;
use App\Models\Invitation;
use App\Models\PasswordLink;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;

/**
 * The emails that open an account: an invitation, a first-password link, a reset link. Each carries a link, never a
 * password, and says how long the link lasts. Queued through the company's own delivery log (tenant-scoped), so the
 * company sees that it was sent and whether it arrived.
 */
class AccountEmail
{
    public function __construct(private EmailDeliveries $deliveries, private TenantContext $context) {}

    public function invitation(Invitation $invitation, string $token, Tenant $tenant, ?string $inviter, ?string $locale = null): void
    {
        $company = $tenant->branding?->company_name ?? $tenant->name;
        $en = $locale === 'en';

        $this->send($tenant, $invitation->email, $invitation->name, $en ? 'Join '.$company.' on Mohtam' : 'دعوة للانضمام إلى '.$company.' على مهتم', [
            'title' => $en ? 'You are invited to join '.$company : 'دُعيت للانضمام إلى '.$company,
            'body' => $en
                ? ($inviter ? $inviter.' invited you' : 'You were invited').' to work on Mohtam with the '.$company.' team. Press the button to choose your password and start.'
                : ($inviter ? $inviter.' يدعوك' : 'تمت دعوتك').' للعمل على مهتم ضمن فريق '.$company.'. اضغط الزر لتختار كلمة مرورك وتبدأ.',
            'link' => $invitation->url($token),
            'button' => $en ? 'Accept the invitation' : 'قبول الدعوة',
            'note' => $en
                ? 'The link works for '.Invitation::DAYS.' days and only once. If you did not expect this invitation, ignore this email.'
                : 'الرابط صالح لمدة '.Invitation::DAYS.' أيام ويُستخدم مرة واحدة. إن لم تكن تتوقع هذه الدعوة فتجاهل الرسالة.',
            'workspace' => $company,
        ], 'invitation', $invitation->uuid.':'.$invitation->send_count, $locale);
    }

    public function passwordLink(string $email, ?string $name, string $purpose, string $token, Tenant $tenant, ?string $locale = null): void
    {
        $company = $tenant->branding?->company_name ?? $tenant->name;
        $setup = $purpose === 'setup';
        $en = $locale === 'en';

        $this->send($tenant, $email, $name, match (true) {
            $en => $setup ? 'Set up your Mohtam account' : 'Reset your Mohtam password',
            default => $setup ? 'جهّز حسابك على مهتم' : 'إعادة تعيين كلمة المرور في مهتم',
        }, [
            'title' => match (true) {
                $en => $setup ? 'The '.$company.' workspace is ready' : 'Password reset requested',
                default => $setup ? 'مساحة عمل '.$company.' جاهزة' : 'طلب إعادة تعيين كلمة المرور',
            },
            'body' => match (true) {
                $en => $setup ? 'Your company\'s workspace on Mohtam was created and you own it. Choose your password to enter.' : 'We received a request to reset your password. Press the button to choose a new one.',
                default => $setup ? 'أُنشئت مساحة عمل شركتك على مهتم وأنت مالكها. اختر كلمة مرورك لتدخل.' : 'وصلنا طلب لإعادة تعيين كلمة مرور حسابك. اضغط الزر لتختار كلمة مرور جديدة.',
            },
            'link' => PasswordLink::url($token),
            'button' => match (true) {
                $en => $setup ? 'Choose a password' : 'Set a new password',
                default => $setup ? 'اختيار كلمة المرور' : 'تعيين كلمة مرور جديدة',
            },
            'note' => match (true) {
                $en => $setup ? 'The link works for seven days and only once.' : 'The link works for one hour and only once. If you did not ask for this, ignore this email; your password has not changed.',
                default => $setup ? 'الرابط صالح لسبعة أيام ويُستخدم مرة واحدة.' : 'الرابط صالح لساعة واحدة ويُستخدم مرة واحدة. إن لم تطلب ذلك فتجاهل الرسالة؛ كلمة مرورك لم تتغير.',
            },
            'workspace' => $company,
        ], 'account', null, $locale);
    }

    public function verification(User $user, string $token, Tenant $tenant): void
    {
        $company = $tenant->branding?->company_name ?? $tenant->name;
        $en = $user->locale === 'en';

        $this->send($tenant, $user->email, $user->name, $en ? 'Confirm your email on Mohtam' : 'تأكيد بريدك في مهتم', [
            'title' => $en ? 'Confirm your email' : 'تأكيد بريدك الإلكتروني',
            'body' => $en ? 'Press the button to confirm this address belongs to your account in '.$company.'.' : 'اضغط الزر لتأكيد أن هذا العنوان يخص حسابك في '.$company.'.',
            'link' => EmailVerification::url($token),
            'button' => $en ? 'Confirm my email' : 'تأكيد البريد',
            'note' => $en ? 'The link works for '.EmailVerification::HOURS.' hours. If you did not ask for this, ignore this email.' : 'الرابط صالح لمدة '.EmailVerification::HOURS.' ساعة. إن لم تطلب ذلك فتجاهل الرسالة.',
            'workspace' => $company,
        ], 'account', null, $user->locale);
    }

    private function send(Tenant $tenant, string $email, ?string $name, string $subject, array $view, string $kind, ?string $key, ?string $locale = null): void
    {
        $en = $locale === 'en';
        $html = view('emails.account-link', $view + [
            'locale' => $en ? 'en' : 'ar',
            'footer' => $en ? 'Mohtam: an automatic message, please do not reply.' : 'مهتم — رسالة آلية، لا ترد عليها.',
        ])->render();

        $this->context->withTenant($tenant, fn () => $this->deliveries->queue(
            new OutboundEmail($email, $name, $subject, $html, idempotencyKey: $key ? substr(hash('sha256', $key), 0, 64) : ''),
            ['kind' => $kind],
        ));
    }
}
