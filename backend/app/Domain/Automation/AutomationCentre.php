<?php

namespace App\Domain\Automation;

use App\Enums\ActivityType;
use App\Models\Appointment;
use App\Models\AutomationActionLog;
use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\LeadActivity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the automation centre shows above the rules: eight numbers, each next
 * to the same number for the period before, and five pictures — how often it
 * works, how leads qualify, the follow-up rhythm, how much of the work was a
 * machine's and how much a person's, and the funnel that is the whole point:
 * a thousand leads in, the best few dozen in front of a person.
 */
class AutomationCentre
{
    /** Actions that speak to the customer. */
    private const CONTACT = ['send_whatsapp', 'send_email', 'share_file', 'ask_qualification', 'request_appointment', 'start_nurture'];

    /** Activities that are a person reaching the customer. */
    private const HUMAN = ['called', 'whatsapp_sent', 'email_sent', 'contacted', 'whatsapp_clicked', 'no_answer'];

    public function summary(int $days = 7): array
    {
        $now = now();
        $from = $now->copy()->subDays($days);
        $before = $from->copy()->subDays($days);

        $cards = [];

        foreach ([
            'all_actions' => fn ($a, $b) => $this->logs($a, $b)->whereIn('result', [AutomationActionLog::COMPLETED, AutomationActionLog::FAILED, AutomationActionLog::NEEDS_HUMAN])->count(),
            'auto_contacted' => fn ($a, $b) => $this->logs($a, $b)->where('result', AutomationActionLog::COMPLETED)->whereIn('action_type', self::CONTACT)->distinct()->count('lead_id'),
            'follow_ups' => fn ($a, $b) => $this->logs($a, $b)->where('result', AutomationActionLog::COMPLETED)
                ->where(fn ($q) => $q->whereNotNull('scheduled_action_id')->orWhereIn('action_type', ['schedule_follow_up', 'create_call_task'])->orWhere('trigger', 'lead.no_reply'))->count(),
            'qualified' => fn ($a, $b) => Lead::query()->whereBetween('qualified_at', [$a, $b])->count(),
            'appointments' => fn ($a, $b) => Appointment::query()->whereBetween('created_at', [$a, $b])->count(),
            'needs_human' => fn ($a, $b) => $this->logs($a, $b)->where('result', AutomationActionLog::NEEDS_HUMAN)->count(),
            'failed' => fn ($a, $b) => $this->logs($a, $b)->where('result', AutomationActionLog::FAILED)->count(),
        ] as $key => $count) {
            $value = $count($from, $now);
            $previous = $count($before, $from);
            $cards[$key] = ['value' => $value, 'previous' => $previous, 'change' => $value - $previous];
        }

        // Switched off, or on and not matched for a month.
        $inactive = AutomationRule::query()
            ->where(fn ($q) => $q->where('is_enabled', false)
                ->orWhereNull('last_matched_at')
                ->orWhere('last_matched_at', '<', $now->copy()->subDays(30)))
            ->count();
        $cards['inactive_rules'] = ['value' => $inactive, 'previous' => null, 'change' => null];

        $completed = $this->logs($from, $now)->where('result', AutomationActionLog::COMPLETED)->count();
        $failed = $cards['failed']['value'];

        return [
            'days' => $days,
            'cards' => $cards,
            'success_rate' => $completed + $failed > 0 ? (int) round($completed / ($completed + $failed) * 100) : null,
            'qualification' => $this->qualification($from, $now),
            'follow_up_trend' => $this->daily($days < 14 ? 14 : $days, fn ($a, $b) => $this->logs($a, $b)->where('result', AutomationActionLog::COMPLETED)
                ->where(fn ($q) => $q->whereNotNull('scheduled_action_id')->orWhereIn('action_type', ['schedule_follow_up', 'create_call_task'])->orWhere('trigger', 'lead.no_reply'))->count()),
            'human_vs_automated' => $this->humanVsAutomated($days < 14 ? 14 : $days),
            'funnel' => $this->funnel($from, $now),
        ];
    }

    private function logs(Carbon $from, Carbon $to)
    {
        return AutomationActionLog::query()->whereBetween('happened_at', [$from, $to]);
    }

    private function qualification(Carbon $from, Carbon $to): array
    {
        return Lead::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('qualification_status as status, count(*) as total')
            ->groupBy('qualification_status')
            ->pluck('total', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    private function daily(int $days, callable $count): array
    {
        return collect(range($days - 1, 0))->map(function (int $ago) use ($count) {
            $start = now()->subDays($ago)->startOfDay();

            return ['date' => $start->toDateString(), 'value' => $count($start, $start->copy()->endOfDay())];
        })->all();
    }

    private function humanVsAutomated(int $days): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $automated = AutomationActionLog::query()
            ->where('happened_at', '>=', $from)
            ->where('result', AutomationActionLog::COMPLETED)
            ->selectRaw('date(happened_at) as day, count(*) as total')
            ->groupBy(DB::raw('date(happened_at)'))
            ->pluck('total', 'day');

        $human = LeadActivity::query()
            ->where('created_at', '>=', $from)
            ->whereNotNull('user_id')
            ->whereIn('type', self::HUMAN)
            ->selectRaw('date(created_at) as day, count(*) as total')
            ->groupBy(DB::raw('date(created_at)'))
            ->pluck('total', 'day');

        return collect(range($days - 1, 0))->map(function (int $ago) use ($automated, $human) {
            $day = now()->subDays($ago)->toDateString();

            return ['date' => $day, 'automated' => (int) ($automated[$day] ?? 0), 'human' => (int) ($human[$day] ?? 0)];
        })->all();
    }

    /**
     * The funnel the product is for: everything that arrived, what was real,
     * what automation reached, who engaged, who qualified, who became a
     * priority — and how few of them needed a person.
     */
    private function funnel(Carbon $from, Carbon $to): array
    {
        $leads = Lead::query()->whereBetween('created_at', [$from, $to]);

        $contacted = AutomationActionLog::query()
            ->where('result', AutomationActionLog::COMPLETED)
            ->whereIn('action_type', self::CONTACT)
            ->whereIn('lead_id', (clone $leads)->select('id'))
            ->distinct()
            ->count('lead_id');

        return [
            ['key' => 'received', 'count' => (clone $leads)->count()],
            ['key' => 'valid', 'count' => (clone $leads)->where('status', '!=', 'invalid')->where(fn ($q) => $q->whereNotNull('phone_normalized')->orWhereNotNull('email'))->count()],
            ['key' => 'auto_contacted', 'count' => $contacted],
            ['key' => 'engaged', 'count' => (clone $leads)->whereNotNull('first_response_at')->count()],
            ['key' => 'qualified', 'count' => (clone $leads)->where('qualification_status', 'qualified')->count()],
            ['key' => 'priority', 'count' => (clone $leads)->where('is_priority', true)->count()],
            ['key' => 'sales', 'count' => (clone $leads)->where('automation_state', Lead::AUTOMATION_HANDED_OFF)->whereNot('needs_human_reason', 'unreached')->count()],
        ];
    }

    /** The Action History, newest first, filtered the way a manager asks. */
    public function history(array $filters, int $perPage = 30)
    {
        return AutomationActionLog::query()
            ->with(['lead:id,uuid,full_name,score', 'rule:id,uuid,name'])
            ->when($filters['result'] ?? null, fn ($q, $r) => $q->where('result', $r))
            ->when($filters['action'] ?? null, fn ($q, $a) => $q->where('action_type', $a))
            ->when($filters['rule'] ?? null, fn ($q, $uuid) => $q->whereHas('rule', fn ($r) => $r->where('uuid', $uuid)))
            ->when($filters['lead'] ?? null, fn ($q, $uuid) => $q->whereHas('lead', fn ($l) => $l->where('uuid', $uuid)))
            ->orderByDesc('happened_at')
            ->orderByDesc('id')
            ->paginate(min(100, max(10, $perPage)));
    }

    /** The human activities a summary compares against. */
    public static function humanTypes(): array
    {
        return array_values(array_filter(ActivityType::values(), fn ($t) => in_array($t, self::HUMAN, true)));
    }
}
