<?php

namespace App\Domain\Mail;

use App\Domain\Mail\Providers\LogMailer;
use App\Domain\Mail\Providers\ResendMailer;
use App\Domain\Mail\Providers\SesMailer;
use App\Domain\Mail\Providers\SmtpMailer;

/** Provider key → provider. The one place a provider is wired in. */
class MailerRegistry
{
    private const PROVIDERS = ['log' => LogMailer::class, 'smtp' => SmtpMailer::class, 'ses' => SesMailer::class, 'resend' => ResendMailer::class];

    /** The provider chosen in settings; an unknown name falls back to the log, never to something that sends. */
    public function current(): MailerProvider
    {
        return $this->get((string) config('leadflow_mail.provider', 'log'));
    }

    /** False while mail is only written down: nobody receives a link sent this way. */
    public function delivers(): bool
    {
        return $this->current()->key() !== 'log';
    }

    public function get(string $key): MailerProvider
    {
        return app(self::PROVIDERS[$key] ?? LogMailer::class);
    }

    /** @return list<MailerProvider> */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), array_values(self::PROVIDERS));
    }
}
