<?php

namespace App\Domain\Leads;

use App\Enums\LeadStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The pipeline as numbers a person can read at a glance.
 *
 * Takes a lead query that already carries the caller's filters and visibility,
 * so a rep sees the pipeline of the leads they may see and a manager the whole
 * tenant — the same scoping as the board, the list and the reports.
 *
 * Three kinds of figure, kept apart on purpose:
 *   funnel   how many leads EVER reached each stage (evidence-based, so a lead
 *            that was booked and then bought still counts as an appointment)
 *   stages   what sits in each stage RIGHT NOW: count, value, age, hot ones
 *   health   open / hot / stalled / response time / value
 */
class PipelineOverview
{
    /** Days with no contact, and nothing scheduled, before an open lead is stalled. */
    public const STALLED_AFTER_DAYS = 3;

    private const FUNNEL = ['new', 'contacted', 'replied', 'qualified', 'appointment', 'negotiation', 'won'];

    /** What an open lead is likely worth: its stated budget, else the project's starting price. */
    private const VALUE = '(coalesce(leads.budget_max, (select projects.starting_price from projects where projects.id = leads.project_id), 0))';

    private function open(): string
    {
        return "leads.status not in ('".LeadStatus::Won->value."', '".LeadStatus::Lost->value."', '".LeadStatus::Invalid->value."', '".LeadStatus::NoResponse->value."')";
    }

    private function stalled(): string
    {
        return sprintf(
            "(%s and coalesce(leads.last_contact_at, leads.created_at) < now() - interval '%d days'
              and (leads.next_follow_up_at is null or leads.next_follow_up_at < now()))",
            $this->open(),
            self::STALLED_AFTER_DAYS,
        );
    }

    public function __construct(private Builder $leads) {}

    public function build(): array
    {
        return [
            'health' => $this->health(),
            'funnel' => $this->funnel(),
            'stages' => $this->stages(),
            'classification' => $this->classification(),
            'hot' => $this->watchlist("leads.temperature = 'hot' and ".$this->open(), 'leads.score desc'),
            'stalled' => $this->watchlist($this->stalled(), 'coalesce(leads.last_contact_at, leads.created_at) asc'),
            'workload' => $this->workload(),
        ];
    }

    /** Where the leads came from — the platforms, largest first, with the tail folded into one slice. */
    public function sources(int $limit = 5): array
    {
        $rows = $this->base()
            ->selectRaw("coalesce(nullif(leads.platform, ''), 'other') as platform, count(*) as total")
            ->groupBy(DB::raw('1'))
            ->orderByDesc(DB::raw('count(*)'))
            ->get();

        $head = $rows->take($limit);
        $tail = (int) $rows->skip($limit)->sum('total');

        return $head->map(fn ($row) => ['key' => (string) $row->platform, 'count' => (int) $row->total])
            ->when($tail > 0, fn ($c) => $c->push(['key' => 'other', 'count' => $tail]))
            ->values()
            ->all();
    }

    /** Leads per day, zero-filled, so a quiet day is a low point and not a missing one. */
    public function trend(int $days = 30): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $counts = $this->base()
            ->where('leads.created_at', '>=', $from)
            ->selectRaw('leads.created_at::date as day, count(*) as total')
            ->groupBy(DB::raw('1'))
            ->pluck('total', 'day');

        return collect(range(0, $days - 1))->map(function (int $offset) use ($from, $counts) {
            $day = $from->copy()->addDays($offset)->toDateString();

            return ['date' => $day, 'count' => (int) ($counts[$day] ?? 0)];
        })->all();
    }

    private function base(): Builder
    {
        return (clone $this->leads)->reorder();
    }

    public function health(): array
    {
        $row = $this->base()->selectRaw(sprintf(
            "count(*) as total,
             count(*) filter (where %1\$s) as open,
             count(*) filter (where leads.temperature = 'hot' and %1\$s) as hot,
             count(*) filter (where %2\$s) as stalled,
             count(*) filter (where leads.status = 'won') as won,
             coalesce(sum(%3\$s) filter (where %1\$s), 0) as open_value,
             coalesce(sum(leads.deal_value_minor) filter (where leads.status = 'won'), 0) as won_value_minor,
             avg(extract(epoch from (leads.first_contact_at - leads.created_at))) filter (where leads.first_contact_at is not null) as response_seconds",
            $this->open(),
            $this->stalled(),
            self::VALUE,
        ))->first();

        $total = (int) $row->total;

        return [
            'total' => $total,
            'open' => (int) $row->open,
            'hot' => (int) $row->hot,
            'stalled' => (int) $row->stalled,
            'won' => (int) $row->won,
            'open_value' => round((float) $row->open_value, 2),
            'won_value' => round(((int) $row->won_value_minor) / 100, 2),
            'win_rate' => $total > 0 ? round(((int) $row->won) / $total * 100, 1) : null,
            'avg_response_seconds' => $row->response_seconds !== null ? (int) round((float) $row->response_seconds) : null,
            'stalled_after_days' => self::STALLED_AFTER_DAYS,
        ];
    }

    /** @return array<int, array{key: string, count: int, keep: ?float}> */
    public function funnel(): array
    {
        $beyond = fn (array $statuses) => "leads.status in ('".implode("', '", $statuses)."')";

        $row = $this->base()->selectRaw(sprintf(
            "count(*) as new,
             count(*) filter (where leads.first_contact_at is not null or leads.status <> 'new') as contacted,
             count(*) filter (where leads.first_response_at is not null or %s) as replied,
             count(*) filter (where leads.qualification_status = 'qualified' or %s) as qualified,
             count(*) filter (where %s) as appointment,
             count(*) filter (where %s) as negotiation,
             count(*) filter (where leads.status = 'won') as won",
            $beyond(['replied', 'qualified', 'appointment', 'negotiation', 'won']),
            $beyond(['qualified', 'appointment', 'negotiation', 'won']),
            $beyond(['appointment', 'negotiation', 'won']),
            $beyond(['negotiation', 'won']),
        ))->first();

        $steps = [];
        $previous = null;

        foreach (self::FUNNEL as $key) {
            $count = (int) $row->{$key};
            // Capped at 100: evidence-based counts can disagree by a lead or two
            // (a reply logged on a lead still marked new), and a stage that
            // converts at 103% is a bug report, not an insight.
            $keep = $previous === null ? null : ($previous > 0 ? min(100.0, round($count / $previous * 100, 1)) : null);
            $steps[] = ['key' => $key, 'count' => $count, 'keep' => $keep];
            $previous = $count;
        }

        return $steps;
    }

    /** Where leads sit right now — every column of the board, including the two ends. */
    private function stages(): array
    {
        $rows = $this->base()
            ->select('leads.status')
            ->selectRaw(sprintf(
                "count(*) as total,
                 coalesce(sum(%s), 0) as value,
                 avg(extract(epoch from (now() - leads.created_at))) / 86400 as avg_age_days,
                 count(*) filter (where leads.temperature = 'hot') as hot",
                self::VALUE,
            ))
            ->groupBy('leads.status')
            ->get()
            ->keyBy(fn ($row) => $row->status instanceof LeadStatus ? $row->status->value : (string) $row->status);

        return collect(LeadStatus::pipeline())->map(function (LeadStatus $status) use ($rows) {
            $row = $rows->get($status->value);

            return [
                'status' => $status->value,
                'total' => (int) ($row->total ?? 0),
                'value' => round((float) ($row->value ?? 0), 2),
                'avg_age_days' => $row ? round((float) $row->avg_age_days, 1) : null,
                'hot' => (int) ($row->hot ?? 0),
            ];
        })->all();
    }

    /** Open leads by classification, zero-filled so a chart always has every slice. */
    public function classification(): array
    {
        $rows = $this->base()
            ->whereRaw($this->open())
            ->selectRaw(LeadClassification::sql().' as class, count(*) as total')
            ->groupBy(DB::raw('1'))
            ->pluck('total', 'class');

        return collect(LeadClassification::values())
            ->map(fn (string $key) => ['key' => $key, 'count' => (int) ($rows[$key] ?? 0)])
            ->all();
    }

    /** The few leads a person should open first, not a table. */
    private function watchlist(string $where, string $order): array
    {
        return $this->base()
            ->whereRaw($where)
            ->with(['assignee:id,uuid,name', 'project:id,uuid,name'])
            ->orderByRaw($order)
            ->limit(5)
            ->get()
            ->map(fn ($lead) => [
                'id' => $lead->uuid,
                'name' => $lead->full_name,
                'status' => $lead->status->value,
                'score' => $lead->score,
                'classification' => LeadClassification::for($lead)['key'],
                'project' => $lead->project?->name,
                'assignee' => $lead->assignee?->name,
                'idle_days' => (int) floor(($lead->last_contact_at ?? $lead->created_at)->diffInDays(now(), true)),
            ])
            ->all();
    }

    /** Open leads per owner — a measure of load, not a league table. */
    private function workload(): array
    {
        return $this->base()
            ->leftJoin('users', 'users.id', '=', 'leads.assigned_to')
            ->whereRaw($this->open())
            ->select([DB::raw("coalesce(users.name, '—') as name"), DB::raw('users.uuid as user_id')])
            ->selectRaw(sprintf(
                "count(*) as open, count(*) filter (where leads.temperature = 'hot') as hot, count(*) filter (where %s) as stalled",
                $this->stalled(),
            ))
            ->groupBy('users.name', 'users.uuid')
            ->orderByDesc(DB::raw('count(*)'))
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'user_id' => $row->user_id,
                'name' => (string) $row->name,
                'open' => (int) $row->open,
                'hot' => (int) $row->hot,
                'stalled' => (int) $row->stalled,
            ])
            ->all();
    }
}
