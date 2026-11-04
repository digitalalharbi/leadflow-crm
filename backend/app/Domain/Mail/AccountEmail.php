<?php

namespace App\Domain\Mail;

use App\Domain\Auth\EmailVerification;
use App\Models\Invitation;
use App\Models\PasswordLink;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ImportantNotification;
use App\Support\Brand;
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

    /**
     * Something changed on this person's account: they hear it from us, in case it was not them. Never gets in the way of
     * the change itself, and carries no secret. The platform's own accounts belong to no company's delivery log.
     */
    public function securityNotice(User $user, string $event, ?string $to = null): void
    {
        $tenant = $user->tenant_id ? Tenant::with('branding')->find($user->tenant_id) : null;

        if ($tenant === null) {
            return;
        }

        $en = $user->locale === 'en';
        [$title, $body] = match ($event) {
            'password_changed' => $en ? ['Your password was changed', 'The password of your Mohtam account has just been changed.'] : ['تغيّرت كلمة مرورك', 'تغيّرت كلمة مرور حسابك في مهتم للتو.'],
            'email_changed' => $en ? ['Your sign-in email was changed', 'The email of your Mohtam account was changed to '.$user->email.'.'] : ['تغيّر بريد الدخول', 'تغيّر بريد حسابك في مهتم إلى '.$user->email.'.'],
            'google_linked' => $en ? ['Google sign-in is on', 'Your Google account can now sign in to your Mohtam account.'] : ['ربطت حساب Google', 'أصبح بإمكان حسابك في Google تسجيل الدخول إلى حسابك في مهتم.'],
            'google_unlinked' => $en ? ['Google sign-in is off', 'Your Google account can no longer sign in to your Mohtam account.'] : ['أُزيل ربط Google', 'لم يعد بإمكان حسابك في Google تسجيل الدخول إلى حسابك في مهتم.'],
            'new_device' => $en ? ['A new device signed in', 'Your Mohtam account was just opened on a device we have not seen before.'] : ['دخول من جهاز جديد', 'فُتح حسابك في مهتم للتو من جهاز لم نره من قبل.'],
            'session_revoked' => $en ? ['A session was ended', 'A signed-in device was removed from your Mohtam account.'] : ['أُنهيت جلسة', 'أُزيل جهاز من الأجهزة المسجّلة في حسابك في مهتم.'],
            'api_key_created' => $en ? ['An API key was created', 'A new API key was created in your company on Mohtam.'] : ['أُنشئ مفتاح API', 'أُنشئ مفتاح API جديد في شركتك على مهتم.'],
            'api_key_rotated' => $en ? ['An API key was rotated', 'An API key in your company on Mohtam was replaced.'] : ['دُوّر مفتاح API', 'استُبدل مفتاح API في شركتك على مهتم.'],
            'webhook_secret_rotated' => $en ? ['A webhook secret was rotated', 'A webhook secret in your company on Mohtam was replaced.'] : ['دُوّر سرّ Webhook', 'استُبدل سرّ Webhook في شركتك على مهتم.'],
            'mfa_enabled' => $en ? ['Two-step sign-in is on', 'Two-step sign-in was turned on for your Mohtam account.'] : ['فُعّل التحقق بخطوتين', 'فُعّل التحقق بخطوتين على حسابك في مهتم.'],
            'mfa_disabled' => $en ? ['Two-step sign-in is off', 'Two-step sign-in was turned off for your Mohtam account.'] : ['أُوقف التحقق بخطوتين', 'أُوقف التحقق بخطوتين على حسابك في مهتم.'],
        };

        rescue(fn () => $user->notify(new ImportantNotification('security_alert', $title, $body, null, '/profile#security', 'warning')));

        rescue(fn () => $this->send($tenant, $to ?? $user->email, $user->name, $title, [
            'title' => $title,
            'body' => $body,
            'link' => rtrim((string) config('integrations.app_url'), '/').'/forgot-password',
            'button' => $en ? 'Secure my account' : 'تأمين حسابي',
            'note' => $en ? 'If this was you, there is nothing to do. If not, reset your password now and write to '.Brand::EMAIL.'.' : 'إن كنت أنت فلا شيء عليك. وإن لم تكن أنت فأعد تعيين كلمة المرور الآن وراسلنا على '.Brand::EMAIL.'.',
            'workspace' => $tenant->branding?->company_name ?? $tenant->name,
        ], 'security', null, $user->locale));
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
