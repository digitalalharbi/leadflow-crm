<?php

namespace App\Domain\Leads;

use App\Enums\ActivityType;
use App\Enums\MessageDirection;
use App\Models\Appointment;
use App\Models\AutomationActionLog;
use App\Models\Call;
use App\Models\FileShare;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadEngagement;
use App\Models\LeadMessage;
use App\Models\LeadSourceEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Everything that has happened to one lead, as one list.
 *
 * It lived in six places — the activity log, the message thread, calls,
 * appointments, the automation trace, file opens — and to answer "what has this
 * person been through" a rep opened all of them. One entry per event, each of a
 * single kind (source, automation, whatsapp, email, file, call, appointment,
 * stage, score, revenue, note), newest first, with who did it: the customer, a
 * rule, a colleague, or the system.
 *
 * Titles are short on purpose; the screen is icons and colour, not paragraphs.
 */
class JourneyFeed
{
    public const KINDS = ['source', 'automation', 'whatsapp', 'email', 'file', 'call', 'appointment', 'followup', 'stage', 'score', 'revenue', 'note'];

    private const LIMIT = 250;

    /** @return list<array<string,mixed>> */
    public function for(Lead $lead): array
    {
        $entries = collect()
            ->merge($this->source($lead))
            ->merge($this->automation($lead))
            ->merge($this->messages($lead))
            ->merge($this->files($lead))
            ->merge($this->calls($lead))
            ->merge($this->appointments($lead))
            ->merge($this->activities($lead));

        return $entries
            ->sortByDesc(fn (array $entry) => $entry['at'])
            ->take(self::LIMIT)
            ->values()
            ->all();
    }

    /** @return Collection<int,array<string,mixed>> */
    private function source(Lead $lead): Collection
    {
        $events = LeadSourceEvent::withoutGlobalScopes()->where('lead_id', $lead->id)->orderBy('received_at')->get()->toBase();

        if ($events->isEmpty()) {
            return collect([$this->entry('source', 'src-'.$lead->id, $lead->created_at, 'وصل العميل', $this->where($lead->platform, $lead->campaign_name), 'system')]);
        }

        return $events->values()->map(fn (LeadSourceEvent $event, int $i) => $this->entry(
            'source',
            'src-'.$event->id,
            $event->received_at ?? $event->created_at,
            $i === 0 && ! $event->was_duplicate ? 'وصل العميل' : 'وصل مرة أخرى',
            $this->where($event->platform, $event->campaign_name),
            'system',
        ));
    }

    private function where(?string $platform, ?string $campaign): ?string
    {
        return collect([$platform ? Str::headline($platform) : null, $campaign])->filter()->implode(' — ') ?: null;
    }

    private function automation(Lead $lead): Collection
    {
        return AutomationActionLog::withoutGlobalScopes()->where('lead_id', $lead->id)->get()->toBase()
            ->map(fn (AutomationActionLog $log) => $this->entry(
                'automation',
                'auto-'.$log->id,
                $log->happened_at ?? $log->created_at,
                (string) ($log->description ?: $log->action_type),
                $log->rule_name,
                'automation',
                $log->result,
            ));
    }

    private function messages(Lead $lead): Collection
    {
        return LeadMessage::withoutGlobalScopes()->with('user:id,name')->where('lead_id', $lead->id)->get()->toBase()
            ->map(function (LeadMessage $message) {
                $inbound = $message->direction === MessageDirection::Inbound;
                $kind = $message->channel->value === 'email' ? 'email' : 'whatsapp';
                $actor = $inbound ? 'customer' : ($message->user_id ? 'person' : 'automation');

                return $this->entry(
                    $kind,
                    'msg-'.$message->id,
                    $message->created_at,
                    $inbound ? 'ردّ العميل' : ($message->user_id ? 'أُرسلت رسالة' : 'أُرسلت رسالة آلية'),
                    Str::limit((string) ($message->subject ?: $message->body), 90),
                    $actor,
                    $message->status?->value,
                    $message->user?->name,
                    ['direction' => $inbound ? 'in' : 'out', 'read' => $message->read_at !== null],
                );
            });
    }

    private function files(Lead $lead): Collection
    {
        $shares = FileShare::withoutGlobalScopes()->with('file:id,name')->where('lead_id', $lead->id)->get()->toBase();
        $opens = LeadEngagement::withoutGlobalScopes()->where('lead_id', $lead->id)
            ->whereIn('type', [LeadEngagement::FILE_OPENED, LeadEngagement::EMAIL_OPENED, LeadEngagement::LINK_CLICKED])->get()->toBase();

        return $shares->map(fn (FileShare $share) => $this->entry(
            'file', 'file-'.$share->id, $share->created_at, 'أُرسل ملف', $share->file?->name, $share->sent_by ? 'person' : 'automation',
        ))->merge($opens->map(fn (LeadEngagement $event) => $this->entry(
            $event->type === LeadEngagement::FILE_OPENED ? 'file' : 'email',
            'eng-'.$event->id,
            $event->happened_at,
            match ($event->type) {
                LeadEngagement::FILE_OPENED => 'فتح العميل الملف',
                LeadEngagement::LINK_CLICKED => 'نقر العميل على رابط',
                default => 'فتح العميل البريد',
            },
            null,
            'customer',
        )));
    }

    private function calls(Lead $lead): Collection
    {
        return Call::withoutGlobalScopes()->where('lead_id', $lead->id)->get()->toBase()->map(fn (Call $call) => $this->entry(
            'call',
            'call-'.$call->id,
            $call->started_at ?? $call->created_at,
            $call->direction === 'inbound' ? 'اتصل العميل' : 'مكالمة صادرة',
            $call->duration_seconds ? gmdate('i:s', (int) $call->duration_seconds).' دقيقة' : null,
            $call->direction === 'inbound' ? 'customer' : 'person',
            $call->status,
        ));
    }

    private function appointments(Lead $lead): Collection
    {
        return Appointment::withoutGlobalScopes()->where('lead_id', $lead->id)->get()->toBase()->map(fn (Appointment $appointment) => $this->entry(
            'appointment',
            'appt-'.$appointment->id,
            $appointment->created_at,
            'موعد '.$appointment->scheduled_at?->format('Y-m-d H:i'),
            $appointment->location,
            'person',
            $appointment->status,
        ));
    }

    /**
     * What the activity log contributes: everything that is not already told by
     * a richer source — messages, files, appointments and the arrival have their
     * own, and listing them twice would double the timeline.
     */
    private function activities(Lead $lead): Collection
    {
        $covered = [
            ActivityType::Created, ActivityType::WhatsappSent, ActivityType::WhatsappReceived, ActivityType::EmailSent,
            ActivityType::EmailReceived, ActivityType::FileShared, ActivityType::AppointmentCreated,
        ];

        return LeadActivity::withoutGlobalScopes()->with('user:id,name')->where('lead_id', $lead->id)
            ->whereNotIn('type', array_map(fn (ActivityType $t) => $t->value, $covered))
            ->get()->toBase()
            ->map(function (LeadActivity $activity) use ($lead) {
                $meta = (array) $activity->meta;
                $automated = ! $activity->user_id && ($meta['by'] ?? null) === 'automation';
                $actor = $activity->user_id ? 'person' : ($automated ? 'automation' : (($meta['by'] ?? null) === 'human' ? 'person' : 'system'));
                $detail = match ($activity->type) {
                    ActivityType::Won => $lead->deal_value_minor !== null ? number_format($lead->deal_value_minor / 100).' ر.س' : $activity->description,
                    default => $activity->description ? Str::limit($activity->description, 120) : null,
                };

                return $this->entry(
                    $this->kindOf($activity->type),
                    'act-'.$activity->id,
                    $activity->created_at,
                    $activity->type->value,
                    $detail,
                    $actor,
                    null,
                    $activity->user?->name ?? ($meta['rule_name'] ?? null),
                    array_intersect_key($meta, array_flip(['from', 'to', 'outcome', 'restored', 'temperature'])),
                    $activity->type->value,
                );
            });
    }

    private function kindOf(ActivityType $type): string
    {
        return match ($type) {
            ActivityType::StatusChanged, ActivityType::Assigned, ActivityType::Lost, ActivityType::Updated => 'stage',
            ActivityType::ScoreChanged, ActivityType::TemperatureChanged, ActivityType::QualificationUpdated => 'score',
            ActivityType::Won => 'revenue',
            ActivityType::Called, ActivityType::NoAnswer, ActivityType::Contacted => 'call',
            ActivityType::WhatsappClicked, ActivityType::WhatsappOptedOut => 'whatsapp',
            ActivityType::EmailOptedOut, ActivityType::EmailBounced => 'email',
            ActivityType::FollowUpCreated, ActivityType::FollowUpCompleted, ActivityType::FollowUpOutcomeRecorded => 'followup',
            ActivityType::HandedOff => 'automation',
            ActivityType::ReEngaged => 'source',
            default => 'note',
        };
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function entry(string $kind, string $id, $at, string $title, ?string $detail, string $actor, ?string $status = null, ?string $by = null, array $meta = [], ?string $type = null): array
    {
        return [
            'id' => $id,
            'kind' => $kind,
            // The activity type when the entry comes from the activity log, so the screen can word it in the user's language.
            'type' => $type,
            'at' => $at?->toIso8601String() ?? now()->toIso8601String(),
            'title' => $title,
            'detail' => $detail,
            // customer | automation | person | system
            'actor' => $actor,
            'status' => $status,
            'by' => $by,
            'meta' => $meta ?: null,
        ];
    }
}
