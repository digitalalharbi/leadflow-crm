<?php

namespace App\Domain\Tracking;

use App\Models\Ad;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Reservation;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;
use App\Support\ProjectIds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which ad, which campaign, which source, which partner brought the customer — all the way to
 * the money.
 *
 * Every lead created in the period carries one unit of credit, divided across the links that
 * touched it by the chosen model; the credit then follows the lead down the funnel, so a bucket's
 * "won" and "revenue" are the share of those that came through it. Leads with no tracking trail
 * are not hidden: they are the "unattributed" bucket, and that bucket's size is the first thing a
 * marketing manager should look at.
 */
class AttributionReport
{
    public const DIMENSIONS = ['channel', 'link', 'campaign', 'ad', 'creative', 'project', 'rep', 'partner', 'landing_page', 'branch', 'source'];

    /** The funnel steps credit follows, in order. */
    public const METRICS = ['leads', 'qualified', 'interested', 'appointments', 'visits', 'reservations', 'won'];

    public function __construct(private Attribution $attribution) {}

    /** @return array<string,mixed> */
    /**
     * Who gets the credit — for the leads that arrived in the period and match the filters.
     *
     * @param  array{campaign_id?:int,platform?:string,ad_id?:int,creative_id?:int,partner_id?:int,rep_id?:int}  $filters  ids of the thing the leads belong to
     */
    public function build(string $model, string $dimension, Carbon $from, Carbon $to, int|array|null $projectId = null, array $filters = []): array
    {
        $model = in_array($model, Attribution::MODELS, true) ? $model : 'last';
        $dimension = in_array($dimension, self::DIMENSIONS, true) ? $dimension : 'channel';

        $leads = Lead::query()->reorder()->whereBetween('created_at', [$from, $to])
            ->tap(fn ($q) => ProjectIds::apply($q, 'project_id', $projectId))
            ->when($filters['campaign_id'] ?? null, fn ($q, $id) => $q->where('campaign_ref_id', $id))
            ->when($filters['platform'] ?? null, fn ($q, $p) => $q->where('platform', $p))
            ->when($filters['ad_id'] ?? null, fn ($q, $id) => $q->where('ad_ref_id', $id))
            ->when($filters['creative_id'] ?? null, fn ($q, $id) => $q->whereIn('ad_ref_id', Ad::query()->where('creative_id', $id)->select('id')))
            ->when($filters['partner_id'] ?? null, fn ($q, $id) => $q->where('partner_id', $id))
            ->when($filters['rep_id'] ?? null, fn ($q, $id) => $q->where('assigned_to', $id))
            ->get(['id', 'status', 'qualification_status', 'qualified_at', 'deal_value_minor', 'first_touch_link_id', 'last_touch_link_id']);

        $ids = $leads->pluck('id');
        $flags = $this->flags($ids);
        $touches = $this->touches($ids, $leads);
        $links = TrackingLink::withTrashed()->with(['campaign:id,name', 'ad:id,name', 'creative:id,name', 'project:id,name', 'rep:id,name'])
            ->whereIn('id', $touches->flatten()->unique())->get()->keyBy('id');

        $buckets = [];
        $unattributed = 0;

        foreach ($leads as $lead) {
            $weights = Attribution::weights($touches->get($lead->id, []), $model);
            $outcome = $flags[$lead->id];

            if ($weights === []) {
                $unattributed++;
                $this->credit($buckets, 'unattributed', null, 1.0, $outcome, $lead);

                continue;
            }

            foreach ($weights as $linkId => $share) {
                [$key, $label] = $this->bucketOf($links->get($linkId), $dimension);
                $this->credit($buckets, $key, $label, $share, $outcome, $lead);
            }
        }

        $clicks = $this->clicks($from, $to, $links, $dimension, $projectId);

        $rows = collect($buckets)->map(function (array $b, string $key) use ($clicks) {
            $b['key'] = $key;
            $b['clicks'] = (int) ($clicks[$key] ?? 0);
            $b['revenue'] = round($b['revenue'] / 100, 2);
            foreach (self::METRICS as $m) {
                $b[$m] = round($b[$m], 2);
            }
            $b['conversion'] = $b['leads'] > 0 ? round($b['won'] / $b['leads'] * 100, 1) : null;

            return $b;
        })->sortByDesc('leads')->values();

        return [
            'model' => $model,
            'dimension' => $dimension,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => [
                'leads' => $leads->count(),
                'unattributed' => $unattributed,
                'unattributed_percent' => $leads->count() > 0 ? round($unattributed / $leads->count() * 100, 1) : null,
            ] + collect(self::METRICS)->mapWithKeys(fn (string $m) => [$m => round($rows->sum($m), 2)])->all() + ['revenue' => round($rows->sum('revenue'), 2)],
            'rows' => $rows->all(),
        ];
    }

    /**
     * The same question under every model, side by side: for each bucket, what each way of counting
     * gives it, so "who really brought the reservations?" can be answered with how much it depends
     * on who is counting. `swing` is the widest gap between two models, as a share of the largest —
     * a high one is a bucket whose credit is an argument, not a fact.
     *
     * @param  array<string,int>  $filters
     * @return array<string,mixed>
     */
    public function compare(string $dimension, Carbon $from, Carbon $to, int|array|null $projectId = null, array $filters = []): array
    {
        $builds = collect(Attribution::MODELS)->mapWithKeys(fn (string $m) => [$m => $this->build($m, $dimension, $from, $to, $projectId, $filters)]);
        $metrics = ['leads', 'interested', 'visits', 'reservations', 'won', 'revenue'];

        $keys = $builds->flatMap(fn (array $b) => collect($b['rows'])->pluck('key'))->unique()->values();
        $rows = $keys->map(function (string $key) use ($builds, $metrics) {
            $by = [];
            $label = null;

            foreach ($builds as $model => $build) {
                $row = collect($build['rows'])->firstWhere('key', $key);
                $label ??= $row['label'] ?? null;
                $by[$model] = collect($metrics)->mapWithKeys(fn (string $m) => [$m => $row[$m] ?? 0])->all();
            }

            $swing = fn (string $m) => ($hi = max(array_column($by, $m))) > 0 ? round(($hi - min(array_column($by, $m))) / $hi * 100, 1) : 0.0;

            return ['key' => $key, 'label' => $label, 'by_model' => $by, 'swing' => ['reservations' => $swing('reservations'), 'revenue' => $swing('revenue')]];
        })->sortByDesc(fn (array $r) => $r['by_model']['last']['leads'])->values();

        return [
            'dimension' => $dimension,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'metrics' => $metrics,
            'models' => Attribution::MODELS,
            'rows' => $rows->all(),
            'totals' => $builds->map(fn (array $b) => collect($metrics)->mapWithKeys(fn (string $m) => [$m => round(collect($b['rows'])->sum($m), 2)])->all())->all(),
            'unattributed_percent' => $builds['last']['totals']['unattributed_percent'],
        ];
    }

    /** @param  array<string,array<string,mixed>>  $buckets */
    private function credit(array &$buckets, string $key, ?string $label, float $share, array $outcome, Lead $lead): void
    {
        $buckets[$key] ??= ['label' => $label, 'revenue' => 0.0] + array_fill_keys(self::METRICS, 0.0);
        $b = &$buckets[$key];

        foreach (self::METRICS as $metric) {
            if ($outcome[$metric]) {
                $b[$metric] += $share;
            }
        }

        $b['revenue'] += $outcome['won'] ? $share * (int) $lead->deal_value_minor : 0;
    }

    /** @return array{0:string,1:?string} */
    private function bucketOf(?TrackingLink $link, string $dimension): array
    {
        if ($link === null) {
            return ['unattributed', null];
        }

        $value = match ($dimension) {
            'channel' => [$link->channel, $link->channel],
            'link' => ['link:'.$link->id, $link->name],
            'campaign' => $link->campaign ? ['campaign:'.$link->campaign_id, $link->campaign->name] : null,
            'ad' => $link->ad ? ['ad:'.$link->ad_id, $link->ad->name] : null,
            'creative' => $link->creative ? ['creative:'.$link->creative_id, $link->creative->name] : null,
            'project' => $link->project ? ['project:'.$link->project_id, $link->project->name] : null,
            'rep' => $link->rep ? ['rep:'.$link->user_id, $link->rep->name] : null,
            'partner' => $link->partner_id ? ['partner:'.$link->partner_id, 'partner:'.$link->partner_id] : null,
            'landing_page' => filled($link->landing_page) ? ['landing:'.$link->landing_page, $link->landing_page] : null,
            'branch' => filled($link->branch) ? ['branch:'.$link->branch, $link->branch] : null,
            'source' => filled($link->utm_source) ? ['source:'.$link->utm_source, $link->utm_source] : null,
        };

        // A link that does not say (no campaign, no partner…) is its own honest bucket.
        return $value ?? ['none', null];
    }

    /** What each lead has reached. @return array<int,array<string,bool>> */
    private function flags(Collection $ids): array
    {
        $appointments = Appointment::query()->whereIn('lead_id', $ids)->get(['lead_id', 'status'])->groupBy('lead_id');
        $reservations = Reservation::query()->whereIn('lead_id', $ids)->whereIn('status', ['pending', 'confirmed', 'converted'])->pluck('lead_id')->flip();

        $out = [];
        foreach (Lead::query()->reorder()->whereIn('id', $ids)->get(['id', 'status', 'qualification_status', 'qualified_at', 'interest_level']) as $lead) {
            $status = $lead->status?->value;
            $mine = $appointments->get($lead->id, collect());
            $out[$lead->id] = [
                'leads' => true,
                'qualified' => $lead->qualification_status?->value === 'qualified' || in_array($status, ['qualified', 'appointment', 'negotiation', 'won'], true),
                'interested' => in_array($lead->interest_level, ['interested', 'hot', 'visit_ready', 'reservation_ready'], true),
                'appointments' => $mine->isNotEmpty(),
                'visits' => $mine->contains(fn ($a) => $a->status === 'completed'),
                'reservations' => $reservations->has($lead->id),
                'won' => $status === 'won',
            ];
        }

        return $out;
    }

    /**
     * The links that touched each lead, oldest first. A lead whose events are gone still has its
     * first and last touch on the lead itself.
     *
     * @return Collection<int,array<int,int>>
     */
    private function touches(Collection $ids, Collection $leads): Collection
    {
        $byLead = TrackingEvent::query()->whereIn('lead_id', $ids)->whereIn('event_type', EventType::touchValues())->whereNotNull('tracking_link_id')
            ->orderBy('occurred_at')->orderBy('id')->get(['lead_id', 'tracking_link_id'])
            ->groupBy('lead_id')
            ->map(fn (Collection $events) => $events->pluck('tracking_link_id')->reduce(
                fn (array $carry, int $id) => $carry !== [] && end($carry) === $id ? $carry : [...$carry, $id], []
            ));

        foreach ($leads as $lead) {
            if (! $byLead->has($lead->id) && ($lead->first_touch_link_id || $lead->last_touch_link_id)) {
                $byLead->put($lead->id, array_values(array_unique(array_filter([$lead->first_touch_link_id, $lead->last_touch_link_id]))));
            }
        }

        return $byLead;
    }

    /**
     * Clicks per bucket: what the links drew, whatever became of it.
     *
     * @return array<string,int>
     */
    private function clicks(Carbon $from, Carbon $to, Collection $knownLinks, string $dimension, int|array|null $projectId): array
    {
        $rows = TrackingEvent::query()->whereBetween('occurred_at', [$from, $to])
            ->whereIn('event_type', ['click', 'whatsapp_click', 'call_click'])->whereNotNull('tracking_link_id')
            ->tap(fn ($q) => ProjectIds::apply($q, 'project_id', $projectId))
            ->selectRaw('tracking_link_id, count(*) as n')->groupBy('tracking_link_id')->pluck('n', 'tracking_link_id');

        $links = TrackingLink::withTrashed()->with(['campaign:id,name', 'ad:id,name', 'creative:id,name', 'project:id,name', 'rep:id,name'])->whereIn('id', $rows->keys())->get()->keyBy('id');

        $out = [];
        foreach ($rows as $linkId => $n) {
            [$key] = $this->bucketOf($links->get($linkId), $dimension);
            $out[$key] = ($out[$key] ?? 0) + (int) $n;
        }

        return $out;
    }
}
