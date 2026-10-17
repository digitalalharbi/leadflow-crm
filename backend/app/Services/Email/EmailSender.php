<?php

namespace App\Services\Email;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Jobs\SendEmailMessage;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Whatsapp\TemplateRenderer;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Writes an email down, then hands it to the queue.
 *
 * The same shape as the WhatsApp sender and for the same reason: the rules are
 * about the *customer*, so they live in one place. A lead who unsubscribed is
 * never emailed, whoever asks — a rep, a rule, or a retry that was already in
 * flight — and the message exists as a row before anything is sent, so a send
 * that dies halfway is a visible pending message and not a customer who got
 * something we have no record of.
 */
class EmailSender
{
    /**
     * Three in ten minutes, per lead.
     *
     * A rule that fires on every status change and a lead who changes status
     * four times is four emails; the ceiling is the difference between a
     * mistake in a rule and a customer who marks us as spam.
     */
    public const BURST = 3;

    public const BURST_MINUTES = 10;

    public function __construct(
        private EmailManager $manager,
        private TemplateRenderer $renderer,
    ) {}

    /** @throws RuntimeException */
    public function send(Lead $lead, string $subject, string $body, ?User $actor = null, ?string $templateName = null): LeadMessage
    {
        $this->guard($lead, $subject, $body);

        $message = LeadMessage::create([
            'lead_id' => $lead->id,
            'user_id' => $actor?->id,
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::Email,
            'provider' => $this->manager->provider(),
            'message_type' => $templateName ? 'template' : 'text',
            'template_name' => $templateName,
            'subject' => Str::limit(trim($subject), 250, ''),
            'body' => $body,
            'status' => MessageStatus::Pending,
            'attempts' => 0,
            'tracking_token' => Str::random(32),
        ]);

        /*
         * The first attempt runs now, in the caller's request, like WhatsApp's.
         *
         * Queued, it would wait for a worker — and a workspace whose worker is
         * down, or a laptop that never ran one, would show every email as
         * "sending" for ever. Only the *retries* go to the queue, where a delay
         * is the point.
         */
        SendEmailMessage::dispatchSync($message->id);

        return $message->refresh();
    }

    /**
     * One of the workspace's saved emails, filled in for this lead.
     *
     * @throws RuntimeException
     */
    public function sendSaved(Lead $lead, MessageTemplate $template, ?User $actor = null, ?string $subjectOverride = null, ?string $bodyOverride = null): LeadMessage
    {
        $subject = $subjectOverride ?? $this->renderer->render((string) $template->subject, $lead, $actor);
        $body = $bodyOverride ?? $this->renderer->render($template->body, $lead, $actor);

        // A template without a subject is still a message; the template's name
        // is the least bad subject, and an empty one is the first thing a spam
        // filter looks for.
        $subject = trim($subject) !== '' ? $subject : $template->name;

        return $this->send($lead, $subject, $body, $actor, $template->name);
    }

    private function guard(Lead $lead, string $subject, string $body): void
    {
        if (blank($lead->email)) {
            throw new RuntimeException('This lead has no email address.');
        }

        if ($lead->email_opt_out_at !== null) {
            throw new RuntimeException('This lead asked not to receive email.');
        }

        if (trim($subject) === '' || trim($body) === '') {
            throw new RuntimeException('The email needs a subject and a body.');
        }

        $recent = LeadMessage::where('lead_id', $lead->id)
            ->where('channel', MessageChannel::Email->value)
            ->where('direction', MessageDirection::Outbound->value)
            ->where('created_at', '>=', now()->subMinutes(self::BURST_MINUTES))
            ->count();

        if ($recent >= self::BURST) {
            throw new RuntimeException('Too many emails to this lead in a short time. Wait a few minutes.');
        }
    }
}
