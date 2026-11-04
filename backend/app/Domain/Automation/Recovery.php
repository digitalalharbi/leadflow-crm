<?php

namespace App\Domain\Automation;

use App\Domain\Notifications\AlertGate;
use App\Enums\ActivityType;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Models\AutomationActionLog;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Notifications\ImportantNotification;
use App\Services\Leads\ActivityLogger;
use Illuminate\Support\Facades\Log;

/**
 * When the channel fails, try the other — and when both do, a person.
 *
 * A WhatsApp that cannot be sent (provider down, number not on WhatsApp, no
 * template for a conversation that is not open) and an email that bounced are
 * not reasons to leave a customer waiting on a promise the product made. The
 * same message goes the other way; if that fails or is impossible too, the lead
 * is handed to a rep with the reason, because nothing automated can reach them.
 *
 * What is NOT recovered: a customer who asked not to receive a channel (that is
 * their decision, not a fault), a rate limit (it clears), and a message that
 * was never valid (empty, a missing template field). Those are reported as they
 * always were.
 */
class Recovery
{
    /** What an action of each kind becomes on the other channel. */
    private const OTHER = ['whatsapp' => 'email', 'email' => 'whatsapp'];

    /** Actions whose delivery can be recovered. */
    private const CONTACT = ['send_whatsapp', 'send_email', 'share_file', 'ask_qualification', 'request_appointment', 'start_nurture'];

    /** The person asked not to be written to: a refusal to respect, not a fault. */
    public static function isConsentRefusal(string $why): bool
    {
        $why = mb_strtolower($why);

        return str_contains($why, 'asked not to receive') || str_contains($why, 'opted out') || str_contains($why, 'unsubscribed') || str_contains($why, 'suppress');
    }

    /**
     * Why a delivery failed, in a word — or null when it is not a channel fault
     * and so is not ours to route around.
     */
    public static function cause(string $why): ?string
    {
        $why = mb_strtolower($why);

        return match (true) {
            str_contains($why, 'asked not to receive') || str_contains($why, 'opted out') => null,
            str_contains($why, 'too many') => null,
            str_contains($why, 'message is empty') || str_contains($why, 'needs a subject') || str_contains($why, 'template needs') => null,
            str_contains($why, 'bounced') => 'bounced',
            str_contains($why, 'no email address') || str_contains($why, 'no usable phone') => 'no_contact_point',
            str_contains($why, 'not connected') => 'provider_unavailable',
            str_contains($why, '24-hour window') || str_contains($why, 'approved template') || str_contains($why, 'not written to us') => 'channel_closed',
            str_contains($why, 'delivery failed') || str_contains($why, 'api error') || str_contains($why, 'timed out') || str_contains($why, 'unavailable') || str_contains($why, 'rejected') => 'provider_unavailable',
            default => null,
        };
    }

    /** True for an action that writes to a person and so can be recovered. */
    public static function isContact(string $type): bool
    {
        return in_array($type, self::CONTACT, true);
    }

    /** @return 'whatsapp'|'email' */
    public static function channelOf(array $action): string
    {
        return match ($action['type'] ?? '') {
            'send_email' => 'email',
            'share_file' => ($action['channel'] ?? 'whatsapp') === 'email' ? 'email' : 'whatsapp',
            default => 'whatsapp',
        };
    }

    /**
     * The failure of a contact action, handled: the other channel, else a person.
     *
     * @param  callable(Lead,array):array  $run  executes an action (the executor's own `execute`)
     * @param  array{ok:bool,description:string}  $failure
     * @return array<string,mixed>|null the replacement result, or null when this failure is not ours to recover
     */
    public function attempt(Lead $lead, array $action, array $failure, callable $run): ?array
    {
        $type = (string) ($action['type'] ?? '');

        if (! self::isContact($type) || isset($action['_recovery']) || ($failure['ok'] ?? true)) {
            return null;
        }

        $cause = self::cause((string) ($failure['description'] ?? ''));

        if ($cause === null || $lead->status?->isClosed()) {
            return null;
        }

        $from = self::channelOf($action);
        $to = self::OTHER[$from];
        $alternate = $this->alternate($action, $to);

        if ($alternate !== null) {
            $result = $run($lead, $alternate + ['_recovery' => true, 'rule_name' => $action['rule_name'] ?? null]);

            // Reached the customer (or will, once quiet hours end).
            if (($result['ok'] ?? false) && ($result['result'] ?? null) !== 'skipped') {
                return [
                    'ok' => true,
                    'result' => AutomationActionLog::RECOVERED,
                    'description' => $this->words($from, $to).' — '.($result['description'] ?? ''),
                ];
            }
        }

        // Both channels failed. A person is called in only for a lead automation has not reached at
        // all; a lead who already received something (a welcome) is not handed over because a
        // brochure or a reminder could not follow it — that failure is reported, as it always was.
        if ($this->reached($lead)) {
            return null;
        }

        return $this->escalate($lead, $action, $from, $to);
    }

    private function reached(Lead $lead): bool
    {
        return LeadMessage::withoutGlobalScopes()->where('lead_id', $lead->id)->where('direction', MessageDirection::Outbound->value)
            ->whereNull('user_id')->where('status', '!=', 'failed')->exists();
    }

    /** A message that failed on its own, after it was sent: the status webhook or a bounce says so. */
    public function afterDeliveryFailure(LeadMessage $message): void
    {
        $direction = $message->direction instanceof MessageDirection ? $message->direction : MessageDirection::tryFrom((string) $message->direction);

        // Only what automation sent: a rep's own message failing is the rep's to see.
        if ($direction !== MessageDirection::Outbound || $message->user_id !== null) {
            return;
        }

        $lead = Lead::withoutGlobalScopes()->find($message->lead_id);

        if (! $lead || $lead->trashed() || $lead->status?->isClosed() || $lead->automation_state === Lead::AUTOMATION_HANDED_OFF) {
            return;
        }

        if (! AlertGate::once("recovery:message:{$message->id}")) {
            return;
        }

        try {
            $from = $message->channel instanceof MessageChannel ? $message->channel->value : (string) $message->channel;
            $to = self::OTHER[$from] ?? 'email';
            $purpose = $this->purposeOf($message);
            $action = $purpose !== null
                ? ['type' => $to === 'email' ? 'send_email' : 'send_whatsapp', 'purpose' => $purpose]
                : ($message->message_type === 'document' ? ['type' => 'share_file', 'channel' => $to, 'send' => true] : null);

            $result = $action !== null
                ? app(ActionExecutor::class)->execute($lead, $action + ['_recovery' => true, 'rule_name' => 'استرداد التسليم'])
                : null;

            $recovered = $result !== null && ($result['ok'] ?? false) && ($result['result'] ?? null) !== 'skipped';

            app(ActionLog::class)->record($lead, null, 'delivery.failed', $action ?? ['type' => 'send_'.$from], $recovered ? AutomationActionLog::RECOVERED : AutomationActionLog::NEEDS_HUMAN, [
                'description' => $recovered
                    ? $this->words($from, $to).'.'
                    : 'فشل التسليم عبر '.$this->name($from).' ولم يمكن إعادة الإرسال عبر '.$this->name($to).' — سُلّم للمندوب.',
            ]);

            if (! $recovered) {
                $this->handOff($lead, $from, $to);
            }
        } catch (\Throwable $e) {
            Log::warning('recovery_failed', ['message' => $message->id, 'error' => $e->getMessage()]);
        }
    }

    /** The same action on the other channel, when there is one. */
    private function alternate(array $action, string $to): ?array
    {
        $type = $action['type'];
        $purpose = $action['purpose'] ?? match ($type) {
            'ask_qualification' => 'qualification',
            'request_appointment' => 'appointment',
            'start_nurture' => 'nurture',
            default => null,
        };

        if ($type === 'share_file') {
            return ['type' => 'share_file', 'kind' => $action['kind'] ?? '', 'send' => true, 'channel' => $to];
        }

        // A qualification message numbers its questions for a WhatsApp reply; it has no email twin.
        if ($type === 'ask_qualification' || $purpose === null) {
            return null;
        }

        return ['type' => $to === 'email' ? 'send_email' : 'send_whatsapp', 'purpose' => $purpose];
    }

    private function purposeOf(LeadMessage $message): ?string
    {
        $name = (string) $message->template_name;

        return $name === '' ? null : MessageTemplate::withoutGlobalScopes()->where('tenant_id', $message->tenant_id)
            ->where(fn ($q) => $q->where('name', $name)->orWhere('meta_template_name', $name))->value('purpose');
    }

    /** @return array<string,mixed> */
    private function escalate(Lead $lead, array $action, string $from, string $to): array
    {
        $this->handOff($lead, $from, $to);

        return [
            'ok' => true,
            'result' => AutomationActionLog::NEEDS_HUMAN,
            'description' => 'تعذّر الإرسال عبر '.$this->name($from).' وعبر '.$this->name($to).' — سُلّم العميل للمندوب.',
        ];
    }

    private function handOff(Lead $lead, string $from, string $to): void
    {
        $lead = $lead->fresh() ?? $lead;

        if ($lead->automation_state !== Lead::AUTOMATION_HANDED_OFF) {
            $lead->forceFill(['automation_state' => Lead::AUTOMATION_HANDED_OFF, 'needs_human_reason' => 'delivery_failed', 'handed_off_at' => now()])->save();
            app(Handoff::class)->freeze($lead);
            app(ActivityLogger::class)->log($lead, ActivityType::HandedOff, null, 'سُلِّم العميل للمندوب: تعذّر الوصول إليه آليًا على '.$this->name($from).' و'.$this->name($to).'.', ['by' => 'automation', 'reason' => 'delivery_failed']);
        }

        $owner = $lead->assigned_to ? User::query()->where('tenant_id', $lead->tenant_id)->where('is_active', true)->find($lead->assigned_to) : null;
        $notification = new ImportantNotification('delivery_failed', 'تعذّر الوصول إلى عميل آليًا', "{$lead->full_name}: فشل واتساب والبريد — اتصل به مباشرة.", $lead->uuid, null, 'critical');
        ($owner ? collect([$owner]) : User::query()->inWorkspace($lead->tenant_id)->managers()->get()->filter(fn (User $user) => $user->reaches($lead->project_id)))
            ->each(fn ($user) => $user->notify(clone $notification));
    }

    private function words(string $from, string $to): string
    {
        return 'تعذّر الإرسال عبر '.$this->name($from).' فأُرسلت عبر '.$this->name($to);
    }

    private function name(string $channel): string
    {
        return $channel === 'email' ? 'البريد' : 'واتساب';
    }
}
