<?php

namespace App\Domain\Files;

use App\Models\FileShare;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\ProjectFile;
use App\Models\User;
use App\Services\Email\EmailSender;
use App\Services\Whatsapp\WhatsappSender;
use RuntimeException;

/**
 * Getting the right file to the lead, by the channel they will read.
 *
 * One path for a rep's button and for an automation rule, because the rules
 * about a customer do not depend on who pressed send: the file is chosen by the
 * same chooser, handed over by the same per-lead tracked link, and recorded on
 * the same timeline. WhatsApp and email differ only in the envelope.
 *
 * The share is created first and stands whatever happens next. A send that
 * fails — the lead opted out, the window is shut, there is no address — leaves
 * a link on the lead's screen for a rep to send by hand, which is strictly
 * better than having neither.
 */
class FileDelivery
{
    public function __construct(
        private FileForTheLead $chooser,
        private ShareFile $sharer,
        private WhatsappSender $whatsapp,
        private EmailSender $email,
    ) {}

    /**
     * @param  'whatsapp'|'email'  $channel
     * @return array{file:ProjectFile,share:FileShare,message:?LeadMessage,reasons:array<int,string>}
     *
     * @throws RuntimeException with the reason, after the share has been made
     */
    public function deliver(Lead $lead, string $channel, ?ProjectFile $file = null, ?string $kind = null, ?User $by = null, string $via = 'manual'): array
    {
        $reasons = [];

        if ($file === null) {
            ['file' => $file, 'reasons' => $reasons, 'reason' => $why] = $this->chooser->pick($lead, $kind);

            if ($file === null) {
                throw new RuntimeException($why ?? 'no_files');
            }
        }

        $share = $this->sharer->forLead($file, $lead, $by, $via === 'manual' ? $channel : $via);

        $message = match ($channel) {
            /*
             * As an attachment, with the tracked link in the caption. The file
             * goes by upload and not by link on purpose: a link makes Meta's
             * servers fetch it, which would register as the customer opening the
             * brochure. The link rides in the caption so the one signal that
             * matters — they tapped it — arrives only when a person did.
             */
            'whatsapp' => $this->whatsapp->sendFile($lead, $file, $share->url(), $by),
            'email' => $this->email->send(
                $lead,
                $file->name,
                $this->emailBody($lead, $file, $share),
                $by,
            ),
            default => throw new RuntimeException('Unknown channel.'),
        };

        return ['file' => $file, 'share' => $share, 'message' => $message, 'reasons' => $reasons];
    }

    private function emailBody(Lead $lead, ProjectFile $file, FileShare $share): string
    {
        $name = $lead->first_name ?: null;

        return ($name ? "مرحبًا {$name}،\n\n" : "مرحبًا،\n\n")
            ."هذا الملف الذي يخص اهتمامك: «{$file->name}»\n"
            .$share->url()."\n\n"
            .'يسعدنا أن نجيب عن أي سؤال.';
    }
}
