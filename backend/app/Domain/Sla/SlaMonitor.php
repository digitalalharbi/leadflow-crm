<?php

namespace App\Domain\Sla;

use App\Domain\Leads\PipelineOverview;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How well the response promise is being kept, in five numbers.
 *
 *   first automated response   arrival → the first message automation sent
 *   first human response       arrival → the first thing a person did
 *   time in stage              how long open leads have been where they are
 *   overdue follow-ups         promised and not done
 *   stalled leads              open, untouched for days, nothing scheduled
 *
 * and one ring: of the leads that arrived in the last 30 days and are past the
 * workspace's first-contact target, the share that were contacted in time.
 */
class SlaMonitor
{
    private const DAYS = 30;

    /** @return array<string,mixed> */
    public function summary(User $user, int $targetMinutes): array
    {
        $leads = fn (): Builder => Lead::query()->visibleTo($user);
        $since = now()->subDays(self::DAYS);
        $open = PipelineOverview::openSql();

        // Only leads whose target has already passed can have missed it or kept it.
        $due = $leads()->where('leads.created_at', '>=', $since)
            ->where('leads.created_at', '<=', now()->subMinutes($targetMinutes))
            ->selectRaw(
                'count(*) as total,
                 count(*) filter (where first_contact_at is not null and first_contact_at <= leads.created_at + (? * interval \'1 minute\')) as within',
                [$targetMinutes],
            )->first();

        $total = (int) $due->total;
        $within = (int) $due->within;

        $tally = $leads()->selectRaw(
            "count(*) filter (where {$open} and next_follow_up_at < now()) as overdue_follow_ups,
             count(*) filter (where first_contact_at is null and status = 'new' and leads.created_at <= ?) as breached_first_contact",
            [now()->subMinutes($targetMinutes)],
        )->first();

        return [
            'target_minutes' => $targetMinutes,
            'compliance' => [
                'percent' => $total > 0 ? (int) round($within / $total * 100) : null,
                'within' => $within,
                'total' => $total,
            ],
            'first_automated' => $this->firstResponse($user, $since, automated: true),
            'first_human' => $this->firstResponse($user, $since, automated: false),
            'stages' => $this->timeInStage($user),
            'overdue_follow_ups' => (int) $tally->overdue_follow_ups,
            'breached_first_contact' => (int) $tally->breached_first_contact,
            'stalled' => $this->stalled($user),
        ];
    }

    /** @return array{median_seconds:?int, average_seconds:?int, samples:int} */
    private function firstResponse(User $user, $since, bool $automated): array
    {
        $visible = Lead::query()->visibleTo($user)->where('leads.created_at', '>=', $since)->select('leads.id', 'leads.created_at');

        $first = $automated
            ? DB::table('lead_messages')->selectRaw('lead_id, min(created_at) as at')
                ->where('direction', 'outbound')->whereNull('user_id')->where('status', '!=', 'failed')->groupBy('lead_id')
            : DB::table('lead_activities')->selectRaw('lead_id, min(created_at) as at')
                ->whereNotNull('user_id')->whereIn('type', ['called', 'no_answer', 'whatsapp_sent', 'email_sent', 'whatsapp_clicked'])->groupBy('lead_id');

        $row = DB::query()->fromSub($visible, 'l')->joinSub($first, 'f', 'f.lead_id', '=', 'l.id')
            ->selectRaw('count(*) as samples,
                percentile_cont(0.5) within group (order by extract(epoch from (f.at - l.created_at))) as median,
                avg(extract(epoch from (f.at - l.created_at))) as average')
            ->first();

        return [
            'median_seconds' => $row->samples > 0 ? max(0, (int) round($row->median)) : null,
            'average_seconds' => $row->samples > 0 ? max(0, (int) round($row->average)) : null,
            'samples' => (int) $row->samples,
        ];
    }

    /** @return list<array{status:string, count:int, average_days:float}> */
    private function timeInStage(User $user): array
    {
        return Lead::query()->visibleTo($user)
            ->whereRaw(PipelineOverview::openSql())
            ->whereNotNull('stage_entered_at')
            ->selectRaw('status, count(*) as total, avg(extract(epoch from (now() - stage_entered_at)) / 86400) as days')
            ->groupBy('status')->orderByDesc('days')->get()
            ->map(fn ($row) => ['status' => $row->status instanceof \BackedEnum ? $row->status->value : (string) $row->status, 'count' => (int) $row->total, 'average_days' => round((float) $row->days, 1)])
            ->all();
    }

    /** @return array{count:int, leads:list<array<string,mixed>>} */
    private function stalled(User $user): array
    {
        $query = fn () => Lead::query()->visibleTo($user)->whereRaw(PipelineOverview::stalledSql());

        return [
            'count' => $query()->count(),
            'leads' => $query()->orderByRaw('coalesce(leads.last_contact_at, leads.created_at) asc')->limit(5)
                ->get(['id', 'uuid', 'full_name', 'last_contact_at', 'created_at'])
                ->map(fn (Lead $lead) => [
                    'id' => $lead->uuid,
                    'name' => $lead->full_name,
                    'days' => (int) ($lead->last_contact_at ?? $lead->created_at)->diffInDays(now()),
                ])->all(),
        ];
    }
}
