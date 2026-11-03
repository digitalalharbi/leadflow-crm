<?php

namespace App\Http\Controllers;

use App\Domain\Automation\Recovery;
use App\Domain\Privacy\SuppressionList;
use App\Enums\ActivityType;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Events\LeadReplied;
use App\Models\EmailDelivery;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\Leads\ActivityLogger;
use App\Services\Leads\LeadScoringService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The customer's answer, back in the conversation it answers.
 *
 * Whatever the mail provider — Postmark, SendGrid or Mailgun inbound — posts a
 * reply or a bounce here. A reply is matched by the address it was sent to
 * (`reply+<token>@`, unique to one email we sent) or by the email it says it
 * answers (`In-Reply-To`), never by the sender's address alone: the same
 * address can belong to leads in two workspaces, and guessing would put one
 * company's customer in another's inbox.
 *
 * A reply does what a WhatsApp reply does — the journey stops waiting, the
 * score moves, the lead is a person's turn. A bounce marks the address as
 * dead so nothing is sent to it again.
 */
class EmailInboundController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ActivityLogger $activities,
        private readonly LeadScoringService $scoring,
    ) {}

    public function receive(Request $request, string $secret)
    {
        $expected = (string) config('services.email_inbound.secret');

        if ($expected === '' || ! hash_equals($expected, $secret)) {
            return ApiResponse::error('not_found', null, 404);
        }

        $events = $this->normalise($request);
        $handled = ['replies' => 0, 'bounces' => 0, 'delivered' => 0, 'unmatched' => 0];

        foreach ($events as $event) {
            if (in_array($event['type'], ['bounce', 'delivered'], true)) {
                $this->markDelivery($event);
            }

            $outcome = match ($event['type']) {
                'bounce' => $this->bounce($event),
                'delivered' => 'delivered',
                default => $this->reply($event),
            };
            $handled[$outcome]++;
        }

        // Always a 200 for something we understood: providers retry anything
        // else, and an unmatched reply will not match better the second time.
        return ApiResponse::success($handled);
    }

    /**
     * Every provider's shape, as one list of replies and bounces.
     *
     * @return array<int, array{type: string, token?: ?string, in_reply_to?: ?string, message_id?: ?string, from?: ?string, subject?: ?string, body?: ?string, email?: ?string, reason?: ?string}>
     */
    private function normalise(Request $request): array
    {
        $payload = $request->all();

        // SendGrid's event webhook: a JSON array of events.
        if (array_is_list($payload) && $payload !== []) {
            return array_values(array_filter(array_map(fn ($e) => match ($e['event'] ?? null) {
                'bounce', 'dropped' => ['type' => 'bounce', 'email' => $e['email'] ?? null, 'reason' => $e['reason'] ?? null],
                'delivered' => ['type' => 'delivered', 'email' => $e['email'] ?? null],
                default => null,
            }, $payload)));
        }

        // Mailgun's event webhook.
        if (isset($payload['event-data'])) {
            $data = $payload['event-data'];

            return ($data['event'] ?? null) === 'failed' && ($data['severity'] ?? null) === 'permanent'
                ? [['type' => 'bounce', 'email' => $data['recipient'] ?? null, 'reason' => $data['delivery-status']['message'] ?? null]]
                : [];
        }

        if (($payload['RecordType'] ?? null) === 'Delivery') {
            return [['type' => 'delivered', 'email' => $payload['Recipient'] ?? null]];
        }

        // Resend's webhook.
        if (in_array($payload['type'] ?? null, ['email.delivered', 'email.bounced', 'email.complained'], true)) {
            return [['type' => $payload['type'] === 'email.delivered' ? 'delivered' : 'bounce', 'email' => ($payload['data']['to'] ?? [null])[0] ?? null, 'reason' => $payload['data']['bounce']['message'] ?? null]];
        }

        // Postmark's bounce webhook.
        if (($payload['RecordType'] ?? null) === 'Bounce') {
            return [['type' => 'bounce', 'email' => $payload['Email'] ?? null, 'reason' => $payload['Description'] ?? ($payload['Type'] ?? null)]];
        }

        // Inbound mail. Postmark is JSON with capitalised keys; SendGrid and
        // Mailgun post form fields.
        $headers = $this->headers($payload);
        $to = (string) ($payload['OriginalRecipient'] ?? $payload['To'] ?? $payload['recipient'] ?? $payload['to'] ?? '');

        return [[
            'type' => 'reply',
            'token' => $payload['MailboxHash'] ?? $this->tokenFrom($to),
            'in_reply_to' => $headers['in-reply-to'] ?? ($payload['In-Reply-To'] ?? null),
            'message_id' => $headers['message-id'] ?? ($payload['MessageID'] ?? $payload['Message-Id'] ?? null),
            'from' => $payload['FromFull']['Email'] ?? $payload['From'] ?? $payload['sender'] ?? $payload['from'] ?? null,
            'subject' => $payload['Subject'] ?? $payload['subject'] ?? null,
            // The reply itself, without the quoted thread below it, when the
            // provider has already separated the two.
            'body' => $payload['StrippedTextReply'] ?? $payload['stripped-text'] ?? $payload['TextBody'] ?? $payload['body-plain'] ?? $payload['text'] ?? null,
        ]];
    }

    /** @return array<string, string> lower-cased header name => value */
    private function headers(array $payload): array
    {
        $headers = [];

        foreach ($payload['Headers'] ?? [] as $header) {
            $headers[strtolower((string) ($header['Name'] ?? ''))] = (string) ($header['Value'] ?? '');
        }

        // SendGrid sends the raw header block as one string.
        if (is_string($payload['headers'] ?? null)) {
            foreach (preg_split('/\r?\n/', $payload['headers']) as $line) {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
            }
        }

        return $headers;
    }

    private function tokenFrom(string $to): ?string
    {
        return preg_match('/reply\+([A-Za-z0-9]{16,64})@/', $to, $m) ? $m[1] : null;
    }

    /** @return 'replies'|'unmatched' */
    private function reply(array $event): string
    {
        $original = $this->original($event);

        if (! $original) {
            return 'unmatched';
        }

        $lead = Lead::withoutGlobalScopes()->find($original->lead_id);

        if (! $lead || ! $lead->tenant) {
            return 'unmatched';
        }

        return $this->context->withTenant($lead->tenant, function () use ($lead, $event) {
            $messageId = trim((string) ($event['message_id'] ?? ''), '<> ') ?: null;

            // Providers retry; the same reply twice is one reply.
            if ($messageId && LeadMessage::where('lead_id', $lead->id)->where('provider_message_id', $messageId)->exists()) {
                return 'replies';
            }

            $last = LeadMessage::where('lead_id', $lead->id)->latest('id')->value('direction');
            $isReply = $last === null || $last === MessageDirection::Outbound->value || $last === MessageDirection::Outbound;

            LeadMessage::create([
                'lead_id' => $lead->id,
                'direction' => MessageDirection::Inbound,
                'channel' => MessageChannel::Email,
                'provider' => 'email_inbound',
                'provider_message_id' => $messageId,
                'message_type' => 'text',
                'subject' => Str::limit(trim((string) ($event['subject'] ?? '')), 250, ''),
                'body' => Str::limit(trim((string) ($event['body'] ?? '')), 10000, ''),
                'status' => MessageStatus::Received,
            ]);

            $lead->first_response_at ??= now();
            $lead->last_contact_at = now();
            $this->scoring->apply($lead);
            $lead->saveQuietly();

            $this->activities->log($lead, ActivityType::EmailReceived, null, Str::limit((string) ($event['subject'] ?? ''), 140));

            if ($isReply) {
                $lead->stopWaitingForReply();
                LeadReplied::dispatch($lead, null, ['channel' => 'email']);
            }

            return 'replies';
        });
    }

    /** The email this one answers: by its own reply address first, then by its headers. */
    private function original(array $event): ?LeadMessage
    {
        if (filled($event['token'] ?? null)) {
            $byToken = LeadMessage::withoutGlobalScopes()
                ->where('tracking_token', $event['token'])
                ->where('channel', MessageChannel::Email->value)
                ->first();

            if ($byToken) {
                return $byToken;
            }
        }

        $inReplyTo = trim((string) ($event['in_reply_to'] ?? ''), '<> ');

        return $inReplyTo === '' ? null : LeadMessage::withoutGlobalScopes()
            ->where('provider_message_id', $inReplyTo)
            ->where('channel', MessageChannel::Email->value)
            ->first();
    }

    /** @return 'bounces'|'unmatched' */
    /** What the provider says happened to the last email the product accepted for this address, across its own log. */
    private function markDelivery(array $event): void
    {
        $email = mb_strtolower(trim((string) ($event['email'] ?? '')));

        if ($email === '') {
            return;
        }

        EmailDelivery::withoutGlobalScopes()->where('to_email', $email)->where('status', EmailDelivery::SENT)->where('updated_at', '>=', now()->subDays(3))
            ->latest('id')->first()?->forceFill(['status' => $event['type'] === 'delivered' ? EmailDelivery::DELIVERED : EmailDelivery::BOUNCED])->save();
    }

    private function bounce(array $event): string
    {
        $email = mb_strtolower(trim((string) ($event['email'] ?? '')));

        if ($email === '') {
            return 'unmatched';
        }

        // The most recent email we sent to that address — the one that bounced.
        $message = LeadMessage::withoutGlobalScopes()
            ->where('channel', MessageChannel::Email->value)
            ->where('direction', MessageDirection::Outbound->value)
            ->whereIn('lead_id', Lead::withoutGlobalScopes()->whereRaw('lower(email) = ?', [$email])->select('id'))
            ->latest('id')
            ->first();

        if (! $message) {
            return 'unmatched';
        }

        $lead = Lead::withoutGlobalScopes()->find($message->lead_id);

        return $this->context->withTenant($lead->tenant, function () use ($lead, $message, $event) {
            $message->forceFill([
                'status' => MessageStatus::Failed,
                'failed_at' => now(),
                'failure_code' => 'bounced',
                'failure_reason' => Str::limit((string) ($event['reason'] ?? 'Bounced'), 250, ''),
            ])->save();

            $lead->forceFill(['email_bounced_at' => now()])->saveQuietly();

            if ($lead->email) {
                app(SuppressionList::class)->add($lead->tenant_id, 'email', $lead->email, 'bounced', null, Str::limit((string) ($event['reason'] ?? ''), 200, ''));
            }
            $this->activities->log($lead, ActivityType::EmailBounced, null, Str::limit((string) ($event['reason'] ?? ''), 140));

            // An automated email that bounced: the same message on WhatsApp, else a person.
            app(Recovery::class)->afterDeliveryFailure($message->refresh());

            return 'bounces';
        });
    }
}
