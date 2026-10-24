<?php

namespace App\Domain\Analytics;

use App\Domain\Leads\InterestSla;
use App\Domain\Leads\PipelineOverview;
use App\Domain\Sla\SlaMonitor;
use App\Models\AutomationActionLog;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What a manager opens the app to see, in one response: today's arrivals, how
 * many got qualified, what is overdue or stalled, how fast the team answers,
 * how many leads become appointments and sales, the revenue — with the team
 * ranked, the sources mixed, the funnel, the daily trend and the SLA ring.
 *
 * Every figure uses the same predicates as the reports it summarises (the
 * quality breakdown's "qualified" and "appointment", the pipeline's "open" and
 * "stalled"), so a tile here and the screen it links to cannot disagree.
 */
class ManagerCenter
{
    public function __construct(private SlaMonitor $sla) {}

    /** @return array<string,mixed> */
    public function build(User $user, int $days, int $targetMinutes): array
    {
        $tz = (string) ($user->tenant?->settings?->timezone ?: 'UTC');
        $since = now()->subDays($days)->startOfDay();
        $today = now()->setTimezone($tz)->startOfDay()->utc();
        $open = PipelineOverview::openSql();

        $leads = fn () => Lead::query()->visibleTo($user)->reorder();

        $cohort = $leads()->where('leads.created_at', '>=', $since)->selectRaw(
            "count(*) as total,
             count(*) filter (where leads.status <> 'invalid') as valid,
             count(*) filter (where leads.first_response_at is not null or leads.status in ('replied','qualified','appointment','negotiation','won')) as engaged,
             count(*) filter (where leads.qualification_status = 'qualified' or leads.status in ('qualified','appointment','negotiation','won')) as qualified,
             count(*) filter (where leads.status in ('appointment','negotiation','won')) as appointments,
             count(*) filter (where leads.status = 'won') as won"
        )->first();

        $now = $leads()->selectRaw(
            "count(*) filter (where leads.created_at >= ?) as today,
             count(*) filter (where {$open} and leads.next_follow_up_at < (now() at time zone 'UTC')) as overdue,
             count(*) filter (where leads.temperature = 'hot' and {$open}) as hot,
             count(*) filter (where leads.temperature = 'warm' and {$open}) as warm,
             count(*) filter (where leads.temperature = 'cold' and {$open}) as cold",
            [$today->format('Y-m-d H:i:s')],
        )->first();

        $revenueMinor = (int) $leads()->where('leads.won_at', '>=', $since)->sum('deal_value_minor');
        $total = (int) $cohort->total;
        $rate = fn (int $n) => $total > 0 ? round($n / $total * 100, 1) : null;
        $sla = $this->sla->summary($user, $targetMinutes, $days);
        // One definition of "qualified" on this screen: the funnel's. The tile and the funnel read the same number.
        $reFunnel = LeadFunnel::counts(Lead::query()->visibleTo($user)->where('leads.created_at', '>=', $since));

        return [
            'days' => $days,
            'kpis' => [
                'leads_today' => (int) $now->today,
                'leads_period' => $total,
                'qualified' => $reFunnel['steps']['qualified'],
                'overdue_follow_ups' => (int) $now->overdue,
                'stalled' => $sla['stalled']['count'],
                'avg_response_seconds' => $sla['first_human']['average_seconds'],
                'appointment_rate' => $rate((int) $cohort->appointments),
                'conversion_rate' => $rate($reFunnel['steps']['won']),
                'revenue' => round($revenueMinor / 100, 2),
            ],
            'sla' => $sla,
            'funnel' => [
                ['key' => 'leads', 'count' => $total],
                ['key' => 'valid', 'count' => (int) $cohort->valid],
                ['key' => 'engaged', 'count' => (int) $cohort->engaged],
                ['key' => 'qualified', 'count' => $reFunnel['steps']['qualified']],
                ['key' => 'appointments', 'count' => (int) $cohort->appointments],
                ['key' => 'won', 'count' => $reFunnel['steps']['won']],
            ],
            'temperature' => [
                ['key' => 'hot', 'count' => (int) $now->hot],
                ['key' => 'warm', 'count' => (int) $now->warm],
                ['key' => 'cold', 'count' => (int) $now->cold],
            ],
            // Lead → Qualified → Interested → Visit → Reservation → Won, for the leads that arrived in the period.
            're_funnel' => $reFunnel,
            // What each figure measures over: a tile says its period, so two numbers never look like one disagreeing.
            'periods' => ['leads_today' => 'today', 'qualified' => 'period', 'overdue_follow_ups' => 'now', 'stalled' => 'now',
                'avg_response_seconds' => 'period', 'appointment_rate' => 'period', 'conversion_rate' => 'period', 'revenue' => 'period',
                'leads_period' => 'period', 'interest' => 'now'],
            'automation' => $this->automation($user, $since, $days),
            'sources' => $this->sources($user, $since),
            'interest' => collect($interest = $this->interest($user))->except('by_rep')->all(),
            'team' => $this->team($user, $since, $open, $interest['by_rep']),
            'trend' => $this->trend($user, $days),
        ];
    }

    /**
     * How much of the work the automation did, and what it cost a person.
     *
     *  - coverage: the period's leads that automation reached with at least one message
     *  - human: the period's leads a person actually touched (a message in their name, a call)
     *  - time to qualify: average from arrival to qualified, for leads qualified in the period
     *  - recovered: deliveries that failed on one channel and reached the customer on the other
     *  - lost, no response: leads lost without the customer ever answering
     *  - revenue by quality: what won deals brought, by the temperature the lead ended at
     *
     * @return array<string,mixed>
     */
    private function automation(User $user, $since, int $days): array
    {
        $reached = "exists (select 1 from lead_messages m where m.lead_id = leads.id and m.direction = 'outbound' and m.user_id is null and m.status <> 'failed')";
        $touched = "(exists (select 1 from lead_messages m where m.lead_id = leads.id and m.direction = 'outbound' and m.user_id is not null)
                     or exists (select 1 from lead_activities a where a.lead_id = leads.id and a.user_id is not null and a.type in ('called','whatsapp_sent','email_sent','whatsapp_clicked')))";
        $answered = "(leads.first_response_at is not null or exists (select 1 from lead_messages m where m.lead_id = leads.id and m.direction = 'inbound'))";

        $cohort = Lead::query()->visibleTo($user)->reorder()->where('leads.created_at', '>=', $since)
            ->selectRaw("count(*) as total, count(*) filter (where {$reached}) as automated, count(*) filter (where {$touched}) as human")->first();
        $total = (int) $cohort->total;
        $pct = fn (int $n) => $total > 0 ? round($n / $total * 100, 1) : null;

        $qualify = Lead::query()->visibleTo($user)->reorder()->where('leads.qualified_at', '>=', $since)->whereColumn('leads.qualified_at', '>=', 'leads.created_at')
            ->selectRaw('count(*) as n, avg(extract(epoch from (leads.qualified_at - leads.created_at))) as seconds')->first();

        $logs = AutomationActionLog::query()->where('happened_at', '>=', $since)->whereIn('result', [AutomationActionLog::RECOVERED, AutomationActionLog::NEEDS_HUMAN])
            ->selectRaw('result, count(*) as n')->groupBy('result')->pluck('n', 'result');

        $lost = Lead::query()->visibleTo($user)->reorder()->where('leads.status', 'lost')->where('leads.lost_at', '>=', $since)
            ->selectRaw("count(*) as total, count(*) filter (where not {$answered}) as silent")->first();

        $byDay = Lead::query()->visibleTo($user)->reorder()->where('leads.created_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->selectRaw("to_char(leads.created_at, 'YYYY-MM-DD') as d, count(*) as total, count(*) filter (where {$reached}) as automated, count(*) filter (where {$touched}) as human")
            ->groupBy('d')->get()->keyBy('d');

        $order = ['hot' => 0, 'warm' => 1, 'cold' => 2, 'unrated' => 3];
        $revenue = Lead::query()->visibleTo($user)->reorder()->where('leads.won_at', '>=', $since)
            ->selectRaw("coalesce(leads.temperature::text, 'unrated') as k, count(*) as deals, coalesce(sum(leads.deal_value_minor), 0) as minor")->groupBy('k')->get()
            ->map(fn ($r) => ['key' => (string) $r->k, 'deals' => (int) $r->deals, 'revenue' => round((int) $r->minor / 100, 2)])
            ->sortBy(fn (array $r) => $order[$r['key']] ?? 9)->values()->all();

        return [
            'coverage_percent' => $pct((int) $cohort->automated),
            'human_percent' => $pct((int) $cohort->human),
            'leads' => $total,
            'avg_seconds_to_qualify' => $qualify->seconds !== null ? (int) round((float) $qualify->seconds) : null,
            'qualified_samples' => (int) $qualify->n,
            'recovered' => (int) ($logs[AutomationActionLog::RECOVERED] ?? 0),
            'needs_human' => (int) ($logs[AutomationActionLog::NEEDS_HUMAN] ?? 0),
            'lost' => ['total' => (int) $lost->total, 'no_response' => (int) $lost->silent, 'percent' => (int) $lost->total > 0 ? round((int) $lost->silent / (int) $lost->total * 100, 1) : null],
            'revenue_by_quality' => $revenue,
            'trend' => collect(range($days - 1, 0))->map(function (int $ago) use ($byDay) {
                $day = now()->subDays($ago)->toDateString();
                $row = $byDay->get($day);

                return ['date' => $day, 'leads' => (int) ($row->total ?? 0), 'automated' => (int) ($row->automated ?? 0), 'human' => (int) ($row->human ?? 0)];
            })->all(),
        ];
    }

    /** Where the period's leads came from, largest first, the tail folded into "other". */
    private function sources(User $user, $since): array
    {
        $rows = Lead::query()->visibleTo($user)->reorder()->where('leads.created_at', '>=', $since)
            ->selectRaw("coalesce(nullif(leads.platform, ''), 'other') as k, count(*) as n")
            ->groupBy(DB::raw('1'))->orderByDesc(DB::raw('count(*)'))->get();

        $head = $rows->take(5)->map(fn ($r) => ['key' => (string) $r->k, 'count' => (int) $r->n]);
        $tail = (int) $rows->skip(5)->sum('n');

        return $head->when($tail > 0, fn ($c) => $c->push(['key' => 'other', 'count' => $tail]))->values()->all();
    }

    /**
     * Interested leads right now, by how far along they are, and how many are waiting too long.
     *
     * @return array{levels:array<string,int>, late:int, due_soon:int, by_rep:array<int,array{interested:int,late:int}>}
     */
    private function interest(User $user): array
    {
        $out = ['levels' => array_fill_keys(array_keys(InterestSla::KEYS), 0), 'late' => 0, 'due_soon' => 0, 'by_rep' => []];

        Lead::query()->visibleTo($user)->reorder()
            ->whereIn('leads.interest_level', array_keys(InterestSla::KEYS))
            ->whereNotIn('leads.status', ['won', 'lost', 'invalid', 'no_response'])
            ->orderBy('leads.interest_level_changed_at')->limit(1000)
            ->get(['leads.id', 'leads.tenant_id', 'leads.project_id', 'leads.status', 'leads.assigned_to', 'leads.interest_level', 'leads.interest_level_changed_at', 'leads.interest_at', 'leads.last_customer_message_at', 'leads.last_contact_at', 'leads.created_at'])
            ->each(function (Lead $lead) use (&$out) {
                $out['levels'][$lead->interest_level]++;
                $state = InterestSla::for($lead)['state'] ?? null;
                $rep = $lead->assigned_to;

                if ($rep) {
                    $out['by_rep'][$rep]['interested'] = ($out['by_rep'][$rep]['interested'] ?? 0) + 1;
                }

                if ($state === 'breached') {
                    $out['late']++;
                    $rep && $out['by_rep'][$rep]['late'] = ($out['by_rep'][$rep]['late'] ?? 0) + 1;
                } elseif ($state === 'due_soon') {
                    $out['due_soon']++;
                }
            });

        return $out;
    }

    /** The team ranked by sales, with what each is carrying — who is overloaded, who is late. */
    private function team(User $user, $since, string $open, array $byRep = []): array
    {
        $rows = Lead::query()->visibleTo($user)->reorder()->whereNotNull('leads.assigned_to')
            ->selectRaw(
                "leads.assigned_to,
                 count(*) filter (where {$open}) as open,
                 count(*) filter (where {$open} and leads.next_follow_up_at < (now() at time zone 'UTC')) as overdue,
                 count(*) filter (where leads.temperature = 'hot' and {$open}) as hot,
                 count(*) filter (where leads.won_at >= ?) as won,
                 coalesce(sum(leads.deal_value_minor) filter (where leads.won_at >= ?), 0) as revenue",
                [$since->format('Y-m-d H:i:s'), $since->format('Y-m-d H:i:s')],
            )->groupBy('leads.assigned_to')->get()->keyBy('assigned_to');

        return User::query()->where('tenant_id', $user->tenant_id)->where('is_active', true)->whereIn('id', $rows->keys())
            ->get(['id', 'uuid', 'name'])
            ->map(fn (User $u) => [
                'id' => $u->uuid,
                'name' => $u->name,
                'open' => (int) $rows[$u->id]->open,
                'overdue' => (int) $rows[$u->id]->overdue,
                'hot' => (int) $rows[$u->id]->hot,
                'won' => (int) $rows[$u->id]->won,
                'revenue' => round((int) $rows[$u->id]->revenue / 100, 2),
                // How many interested leads they carry and how many of those have waited too long.
                'interested' => $byRep[$u->id]['interested'] ?? 0,
                'sla_late' => $byRep[$u->id]['late'] ?? 0,
            ])
            ->sortBy([['won', 'desc'], ['revenue', 'desc'], ['open', 'desc']])
            ->values()->all();
    }

    /** Leads in, qualified and won, per day — oldest first, every day present. */
    private function trend(User $user, int $days): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $count = fn (string $column) => Lead::query()->visibleTo($user)->reorder()->where("leads.{$column}", '>=', $from)
            ->selectRaw("to_char(leads.{$column}, 'YYYY-MM-DD') as d, count(*) as n")->groupBy('d')->pluck('n', 'd');

        $arrived = $count('created_at');
        $qualified = $count('qualified_at');
        $won = $count('won_at');

        return collect(range($days - 1, 0))->map(function (int $ago) use ($arrived, $qualified, $won) {
            $day = now()->subDays($ago)->toDateString();

            return ['date' => $day, 'leads' => (int) ($arrived[$day] ?? 0), 'qualified' => (int) ($qualified[$day] ?? 0), 'won' => (int) ($won[$day] ?? 0)];
        })->all();
    }
}
