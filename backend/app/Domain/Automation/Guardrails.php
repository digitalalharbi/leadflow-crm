<?php

namespace App\Domain\Automation;

use App\Domain\Files\FileForTheLead;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\FileShare;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\Project;
use App\Models\TenantSetting;
use Illuminate\Support\Carbon;

/**
 * What automation may not do to a person, whatever a rule says.
 *
 * A rule is written once and runs for months; the lead it runs on changes
 * underneath it. These limits are checked at the moment of sending, against the
 * lead as it is now, and they only ever apply to *automated* contact — a rep who
 * writes to a customer is a person making a decision, not a rule repeating one.
 *
 * Three outcomes: allow, block (never, with a reason the trace can show), or
 * defer (not now — the verdict carries the moment it becomes acceptable).
 */
class Guardrails
{
    public const ALLOW = 'allow';

    public const BLOCK = 'block';

    public const DEFER = 'defer';

    /** The same template to the same person inside this many hours is a repeat. */
    public const DUPLICATE_WINDOW_HOURS = 24;

    public function __construct(private FileForTheLead $chooser) {}

    /**
     * @param  array{type?:string, kind?:?string, channel?:?string}  $action
     * @param  ?string  $template  the saved message's name, when there is one
     * @return array{verdict:string, code:?string, reason:?string, until:?Carbon}
     */
    public function check(Lead $lead, string $channel, array $action, ?string $template = null, ?string $metaTemplate = null): array
    {
        $type = (string) ($action['type'] ?? '');

        // 1 — a person has the lead. Resuming the journey is the one exception:
        //     `start_nurture` is how a rep hands it back.
        if ($lead->automation_state === Lead::AUTOMATION_HANDED_OFF && $type !== 'start_nurture') {
            return $this->block('human_takeover', 'العميل مع المندوب — توقفت الرسائل الآلية.');
        }

        // 2 — the same message, or the same brochure, twice.
        if ($type === 'share_file') {
            if ($this->brochureAlreadyShared($lead, $action)) {
                return $this->block('brochure_repeat', 'سبق إرسال هذا الملف للعميل — لا يُكرَّر آليًا.');
            }
        } elseif ($template !== null && $this->alreadySent($lead, $channel, array_filter([$template, $metaTemplate]))) {
            return $this->block('duplicate_message', 'أُرسلت هذه الرسالة نفسها للعميل مؤخرًا — لا تُكرَّر.');
        }

        // 3 — the hour of the day.
        if ($until = $this->quietUntil($lead)) {
            return $this->defer('quiet_hours', 'ساعات الهدوء — تُؤجَّل الرسالة إلى '.$until->setTimezone($this->timezone($lead))->format('H:i').'.', $until);
        }

        // 4 — how often, and how soon after the last one.
        $settings = $this->settings($lead);
        $recent = $this->automatedSince($lead, now()->subDay());

        $cap = (int) ($settings?->max_contacts_per_day ?? 6);
        if ($cap > 0 && $recent->count() >= $cap) {
            $until = $recent->sortBy('created_at')->first()->created_at->copy()->addDay();

            return $this->defer('max_frequency', "بلغ العميل حدّ {$cap} رسائل آلية في اليوم — تُؤجَّل.", $until);
        }

        $cooldown = (int) ($settings?->contact_cooldown_minutes ?? 0);
        $last = $recent->sortByDesc('created_at')->first();
        if ($cooldown > 0 && $last && $last->created_at->copy()->addMinutes($cooldown)->isFuture()) {
            return $this->defer('cooldown', "فترة تهدئة {$cooldown} دقيقة بين الرسائل الآلية.", $last->created_at->copy()->addMinutes($cooldown));
        }

        return ['verdict' => self::ALLOW, 'code' => null, 'reason' => null, 'until' => null];
    }

    /** The end of the quiet window, when `now` is inside it. */
    public function quietUntil(Lead $lead, ?Carbon $at = null): ?Carbon
    {
        $quiet = $this->quietHours($lead);

        if (! $quiet['enabled']) {
            return null;
        }

        $tz = $this->timezone($lead);
        $now = ($at ?? now())->copy()->setTimezone($tz);
        [$sh, $sm] = array_map('intval', explode(':', $quiet['start']));
        [$eh, $em] = array_map('intval', explode(':', $quiet['end']));

        $minutes = $now->hour * 60 + $now->minute;
        $start = $sh * 60 + $sm;
        $end = $eh * 60 + $em;

        // The window may cross midnight (21:00 → 08:00) or sit inside a day.
        $inside = $start <= $end ? ($minutes >= $start && $minutes < $end) : ($minutes >= $start || $minutes < $end);

        if (! $inside) {
            return null;
        }

        $until = $now->copy()->setTime($eh, $em);

        if ($until->lte($now)) {
            $until->addDay();
        }

        return $until->setTimezone('UTC');
    }

    /**
     * The project's own quiet hours when it has set them, otherwise the workspace's.
     *
     * @return array{enabled:bool, start:string, end:string}
     */
    public function quietHours(Lead $lead): array
    {
        $settings = $this->settings($lead);
        $project = $lead->project_id ? Project::withoutGlobalScopes()->find($lead->project_id) : null;
        $own = (array) ($project?->automation_settings['quiet_hours'] ?? []);

        return [
            'enabled' => (bool) ($own['enabled'] ?? $settings?->quiet_hours_enabled ?? false),
            'start' => (string) ($own['start'] ?? $settings?->quiet_hours_start ?? '21:00'),
            'end' => (string) ($own['end'] ?? $settings?->quiet_hours_end ?? '08:00'),
        ];
    }

    private function alreadySent(Lead $lead, string $channel, array $names): bool
    {
        return LeadMessage::withoutGlobalScopes()
            ->where('lead_id', $lead->id)
            ->where('channel', $channel)
            ->where('direction', MessageDirection::Outbound->value)
            ->where('status', '!=', MessageStatus::Failed->value)
            ->whereIn('template_name', $names)
            ->where('created_at', '>=', now()->subHours(self::DUPLICATE_WINDOW_HOURS))
            ->exists();
    }

    private function brochureAlreadyShared(Lead $lead, array $action): bool
    {
        ['file' => $file] = $this->chooser->pick($lead, ($action['kind'] ?? null) ?: null);

        return $file !== null
            && FileShare::withoutGlobalScopes()->where('lead_id', $lead->id)->where('project_file_id', $file->id)->exists();
    }

    /** Automated messages only: a person's message has a `user_id`. */
    private function automatedSince(Lead $lead, Carbon $since)
    {
        return LeadMessage::withoutGlobalScopes()
            ->where('lead_id', $lead->id)
            ->where('direction', MessageDirection::Outbound->value)
            ->whereNull('user_id')
            ->where('status', '!=', MessageStatus::Failed->value)
            ->where('created_at', '>=', $since)
            ->get(['id', 'created_at']);
    }

    private function settings(Lead $lead): ?TenantSetting
    {
        return TenantSetting::withoutGlobalScopes()->where('tenant_id', $lead->tenant_id)->first();
    }

    private function timezone(Lead $lead): string
    {
        return $this->settings($lead)?->timezone ?: config('app.timezone');
    }

    private function block(string $code, string $reason): array
    {
        return ['verdict' => self::BLOCK, 'code' => $code, 'reason' => $reason, 'until' => null];
    }

    private function defer(string $code, string $reason, Carbon $until): array
    {
        return ['verdict' => self::DEFER, 'code' => $code, 'reason' => $reason, 'until' => $until];
    }
}
