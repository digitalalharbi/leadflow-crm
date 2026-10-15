<?php

namespace App\Services\Whatsapp;

use App\Enums\ActivityType;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Jobs\RetryWhatsappMessage;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\MessageTemplate;
use App\Models\ProjectFile;
use App\Models\User;
use App\Services\Leads\ActivityLogger;
use App\Services\Leads\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sends a WhatsApp message on behalf of a rep or a rule and records what
 * happened.
 *
 * Every send goes through here, so there is exactly one place that knows the
 * rules: a lead who opted out is never messaged, the message is persisted
 * before we call the provider, and the lead's first-contact timestamp is set
 * the first time anyone actually reaches them.
 *
 * Three kinds of message share that one path — text, an approved template, and
 * a file — because the rules are about the *customer*, not about the shape of
 * what is sent. A template that skipped the opt-out check because it was added
 * later and lived in its own method would be exactly the bug this class exists
 * to prevent.
 */
class WhatsappSender
{
    /**
     * Meta's customer-service window.
     *
     * Free-form messages are allowed only within a day of the customer's last
     * message to us. Outside it Meta answers 131047, and the only thing a
     * business may send is a template it had approved in advance.
     */
    public const WINDOW_HOURS = 24;

    public function __construct(
        private WhatsappManager $manager,
        private PhoneNormalizer $phones,
        private ActivityLogger $activities,
        private TemplateRenderer $renderer,
    ) {}

    /**
     * Whether free text may be sent to this lead right now.
     *
     * Decided from our own record of what they sent, not from asking Meta: the
     * answer is needed before a message is composed, and a failed attempt costs
     * a rate-limit hit and a line in the customer's own record of being
     * contacted.
     */
    public function windowOpen(Lead $lead): bool
    {
        return LeadMessage::inbound()
            ->where('lead_id', $lead->id)
            ->where('channel', MessageChannel::Whatsapp->value)
            ->where('created_at', '>=', now()->subHours(self::WINDOW_HOURS))
            ->exists();
    }

    /**
     * @throws RuntimeException when the workspace or the lead cannot be messaged
     */
    public function send(Lead $lead, string $body, ?User $actor = null, ?string $templateName = null): LeadMessage
    {
        return $this->dispatch($lead, [
            'message_type' => $templateName ? 'template' : 'text',
            'template_name' => $templateName,
            'body' => $body,
        ], SendSpec::text(), $actor);
    }

    /**
     * Sends one of the workspace's saved messages the way that will work.
     *
     * Inside the customer's window it goes as the saved text. Outside it, the
     * same message goes as the Meta template the workspace registered against
     * it — and if there is none, that is reported rather than attempted,
     * because the attempt would only be refused by Meta and recorded on the
     * customer's history as a failed contact.
     *
     * @throws RuntimeException
     */
    public function sendSaved(Lead $lead, MessageTemplate $template, ?User $actor = null, ?string $bodyOverride = null): LeadMessage
    {
        $body = $bodyOverride ?? $this->renderer->render($template->body, $lead, $actor);

        if (trim($body) === '') {
            throw new RuntimeException('The message is empty.');
        }

        /*
         * Free text when it will be accepted: the rep edited it (and an edited
         * message cannot become a template, whose words were fixed when Meta
         * approved them), the window is open, or this is the demo mock, which
         * accepts anything and is reported as unverified for as long as it is
         * the one answering.
         */
        $live = $this->manager->isLive($this->manager->integration());

        if ($bodyOverride !== null || ! $live || $this->windowOpen($lead)) {
            return $this->send($lead, $body, $actor, $template->name);
        }

        if (blank($template->meta_template_name)) {
            throw new RuntimeException(
                'This conversation is outside WhatsApp\'s 24-hour window, and this message has no approved template to start it with.',
            );
        }

        return $this->sendTemplate($lead, $template, $actor, $body);
    }

    /**
     * @throws RuntimeException
     */
    private function sendTemplate(Lead $lead, MessageTemplate $template, ?User $actor, string $previewBody): LeadMessage
    {
        $values = $this->renderer->values($lead, $actor);
        $parameters = [];

        foreach ((array) $template->meta_parameters as $key) {
            $value = $values[$key] ?? null;

            /*
             * Refused rather than filled in with a blank.
             *
             * Meta rejects an empty parameter outright, so the alternative is a
             * send that fails with a code nobody here can read. A lead with no
             * usable name is a reason this particular template cannot go to
             * them, and the sentence says so.
             */
            if (blank($value)) {
                throw new RuntimeException(
                    "This template needs the lead's {$key}, and this lead does not have one.",
                );
            }

            $parameters[] = (string) $value;
        }

        return $this->dispatch($lead, [
            'message_type' => 'template',
            'template_name' => $template->meta_template_name,
            // What the template says, for the conversation view. The words Meta
            // actually sent are the ones it approved, which we do not hold.
            'body' => $previewBody,
        ], SendSpec::template(
            $template->meta_template_name,
            $template->meta_language ?: 'ar',
            $parameters,
        ), $actor);
    }

    /**
     * Sends a project file as an attachment.
     *
     * Needs the window open: a document is a free-form message, and Meta has no
     * template shape for one. Outside the window the caller sends the link in
     * an approved template instead.
     *
     * @throws RuntimeException
     */
    public function sendDocument(Lead $lead, ProjectFile $file, ?string $caption = null, ?User $actor = null): LeadMessage
    {
        return $this->dispatch($lead, [
            'message_type' => 'document',
            'template_name' => null,
            'body' => $caption ?: $file->name,
        ], SendSpec::document(
            (string) $file->path,
            (string) ($file->mime ?: 'application/octet-stream'),
            (string) $file->name,
            $caption,
        ), $actor);
    }

    /**
     * The one path every kind of message takes.
     *
     * @param  array{message_type:string,template_name:?string,body:string}  $attributes
     * @param  array<string,mixed>  $spec
     *
     * @throws RuntimeException
     */
    private function dispatch(Lead $lead, array $attributes, array $spec, ?User $actor): LeadMessage
    {
        $integration = $this->manager->integration();

        if (! $this->manager->canSend($integration)) {
            throw new RuntimeException('WhatsApp is not connected for this workspace.');
        }

        if (! $lead->acceptsWhatsapp()) {
            throw new RuntimeException('This lead has asked not to receive WhatsApp messages.');
        }

        /*
         * Per conversation, not per user: the global API limiter would let one
         * rep send a message a second to the same person, which is how a
         * tenant's WhatsApp number gets reported — and a reported number ends
         * the channel for them, not for us.
         */
        $perMinute = (int) config('integrations.whatsapp.messages_per_lead_per_minute');

        if ($perMinute > 0 && RateLimiter::tooManyAttempts($key = 'wa-send:'.$lead->id, $perMinute)) {
            throw new RuntimeException('Too many messages to this lead. Wait a moment before sending again.');
        }

        $to = $lead->phone_normalized ?: $this->phones->normalize((string) $lead->phone);

        if (! $to) {
            throw new RuntimeException('This lead has no usable phone number.');
        }

        $provider = $this->manager->providerFor($integration);

        /*
         * Free-form text and files need the customer's window open.
         *
         * Checked here and not left to Meta, because a refused attempt is not
         * free: it is a rate-limit hit, and a line on the customer's history
         * that says they were contacted and the contact failed.
         *
         * Applied to the live provider and to files, and not to the demo mock's
         * plain text. That leniency is deliberate and bounded: the mock is what
         * lets a prospect watch a message "send" on a fresh lead, and the
         * integration is reported as unverified for exactly as long as it is
         * the one answering. A file has no such history to preserve, so the
         * rule holds for it everywhere.
         */
        $kind = $spec['kind'] ?? SendSpec::TEXT;

        $needsWindow = $kind === SendSpec::DOCUMENT
            || ($kind === SendSpec::TEXT && $provider->key() === 'whatsapp_cloud');

        if ($needsWindow && ! $this->windowOpen($lead)) {
            throw new RuntimeException(
                'This conversation is outside WhatsApp\'s 24-hour window. Only an approved template can start it.',
            );
        }

        if ($perMinute > 0) {
            RateLimiter::hit($key, 60);
        }

        /*
         * Written before the call, not after. If the provider accepts the
         * message and we then fail to record it, the customer has been
         * contacted and the CRM says otherwise — the one inconsistency that
         * matters here.
         */
        $message = LeadMessage::create([
            'lead_id' => $lead->id,
            'user_id' => $actor?->id,
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::Whatsapp,
            'provider' => $provider->key(),
            'message_type' => $attributes['message_type'],
            'template_name' => $attributes['template_name'],
            'body' => $attributes['body'],
            'status' => MessageStatus::Pending,
            // The recipe, so a retry is the same message and not merely the
            // same words.
            'send_spec' => $spec,
        ]);

        $result = SendSpec::run($provider, $integration, $to, $spec, $attributes['body']);

        $message->forceFill(['attempts' => 1]);

        if (! $result->accepted) {
            /*
             * A failure that describes the attempt rather than the message is
             * worth trying again: the network dropped, or Meta answered 503.
             * The message stays pending and a job carries it, so the rep is
             * told "sending" rather than "failed" for something that is about
             * to succeed — and is told "failed" once it genuinely has.
             *
             * Not retried inline: the rep's request has to come back now, and
             * waiting out a backoff inside it would hold the browser.
             */
            if (SendFailure::isTransient($result->failureCode)) {
                $message->forceFill([
                    'status' => MessageStatus::Pending,
                    'failure_code' => $result->failureCode,
                    'failure_reason' => $result->failureReason,
                    'provider_payload' => $result->payload ?: null,
                ])->save();

                RetryWhatsappMessage::dispatch($message->id)
                    ->delay(RetryWhatsappMessage::delayFor(1));

                return $message;
            }

            $message->forceFill([
                'status' => MessageStatus::Failed,
                'failed_at' => now(),
                'failure_code' => $result->failureCode,
                'failure_reason' => $result->failureReason,
                'provider_payload' => $result->payload ?: null,
            ])->save();

            return $message;
        }

        DB::transaction(function () use ($lead, $message, $result, $actor, $attributes) {
            $message->forceFill([
                'status' => MessageStatus::Sent,
                'sent_at' => now(),
                'provider_message_id' => $result->providerMessageId,
                'provider_payload' => $result->payload ?: null,
            ])->save();

            $lead->forceFill([
                'last_contact_at' => now(),
                'first_contact_at' => $lead->first_contact_at ?? now(),
            ])->saveQuietly();

            $this->activities->log(
                $lead,
                ActivityType::WhatsappSent,
                $actor,
                Str::limit($attributes['body'], 140),
                ['message_uuid' => $message->uuid, 'kind' => $attributes['message_type']],
            );
        });

        return $message->refresh();
    }
}
