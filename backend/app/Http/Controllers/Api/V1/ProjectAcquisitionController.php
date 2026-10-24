<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Permissions;
use App\Domain\Campaigns\CampaignRevenue;
use App\Domain\Funnel\FunnelFilters;
use App\Domain\Funnel\RealEstateFunnel;
use App\Domain\Tracking\AttributionReport;
use App\Domain\Tracking\EventType;
use App\Domain\Tracking\LinkAnalytics;
use App\Domain\Tracking\LinkHealth;
use App\Domain\Tracking\LinkStats;
use App\Http\Controllers\Controller;
use App\Models\LandingForm;
use App\Models\Lead;
use App\Models\Project;
use App\Models\TrackingDestination;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Acquisition: inside a project, where its customers come from and whether the tracking can be trusted — the links, the
 * landing forms, the funnel, the sources, the campaigns, what just happened, who gets the credit, and what is broken.
 *
 * Everything on it is a figure the rest of the product already uses (the lead funnel, the attribution report, the campaign
 * report), put side by side. For those who hold the tracking screens; money only with the revenue permission; a project
 * manager sees only the projects they were given.
 */
class ProjectAcquisitionController extends Controller
{
    /** Health is judged for the links that matter most; the rest are listed without it. */
    private const HEALTH_FOR = 20;

    public function __construct(private LinkAnalytics $analytics, private LinkHealth $health, private LinkStats $stats, private AttributionReport $attribution) {}

    public function __invoke(Request $request, string $uuid)
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $user = $request->user();
        abort_unless(Permissions::has($user, 'tracking.view'), 403);

        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365']])['days'] ?? 30);
        $from = now()->subDays($days - 1)->startOfDay();
        $to = now()->endOfDay();
        $money = Permissions::has($user, 'revenue.view');

        $links = TrackingLink::query()->where('project_id', $project->id)->latest('id')->limit(100)->get();
        $ids = $links->pluck('id')->all();
        $stats = $this->stats->for($ids, $from);
        $ranked = $links->sortByDesc(fn (TrackingLink $l) => [$stats[$l->id]['leads'] ?? 0, $stats[$l->id]['clicks'] ?? 0])->values();
        $healths = $ranked->take(self::HEALTH_FOR)->mapWithKeys(fn (TrackingLink $l) => [$l->id => $this->health->for($l)]);

        return ApiResponse::success([
            'project' => ['id' => $project->uuid, 'name' => $project->name],
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'links' => $ranked->map(fn (TrackingLink $l) => [
                'id' => $l->uuid, 'name' => $l->name, 'channel' => $l->channel, 'mode' => $l->mode, 'token' => $l->token, 'url' => $l->url(), 'is_live' => $l->isLive(),
                'stats' => $money ? $stats[$l->id] : array_merge($stats[$l->id], ['revenue' => null]),
                'health' => $healths[$l->id]['status'] ?? null,
            ])->values(),
            'forms' => $this->forms($project, $from, $to),
            'funnel' => $this->analytics->forLinks($ids, $from, $to, $money)['funnel'],
            'real_estate_funnel' => app(RealEstateFunnel::class)->build(new FunnelFilters($from, $to, [$project->id]), $user, $money),
            'sources' => $this->sources($project, $links, $stats, $from, $to, $money),
            'campaigns' => $this->campaigns($user, $project, $from, $to, $money),
            'events' => $this->events($project),
            'attribution' => [
                'first' => $this->attribution->build('first', 'channel', $from, $to, $project->id),
                'last' => $this->attribution->build('last', 'channel', $from, $to, $project->id),
            ],
            'diagnostics' => $this->diagnostics($project, $ranked, $healths),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function forms(Project $project, $from, $to): array
    {
        $forms = LandingForm::query()->where('project_id', $project->id)->withCount('links')->latest('id')->limit(50)->get();
        $submits = TrackingEvent::query()->where('event_type', 'form_submit')->whereBetween('occurred_at', [$from, $to])
            ->whereIn('tracking_link_id', TrackingLink::query()->where('project_id', $project->id)->select('id'))
            ->join('tracking_links as l', 'l.id', '=', 'tracking_events.tracking_link_id')
            ->selectRaw('l.landing_form_id as form_id, count(*) as n')->groupBy('l.landing_form_id')->pluck('n', 'form_id');

        return $forms->map(fn (LandingForm $f) => [
            'id' => $f->uuid, 'name' => $f->name, 'status' => $f->status, 'links_count' => $f->links_count, 'steps' => $f->steps(), 'submissions' => (int) ($submits[$f->id] ?? 0),
        ])->values()->all();
    }

    /**
     * @param  Collection<int,TrackingLink>  $links
     * @param  array<int,array<string,mixed>>  $stats
     * @return array{channels:list<array<string,mixed>>, unattributed:int}
     */
    private function sources(Project $project, $links, array $stats, $from, $to, bool $money): array
    {
        $channels = $links->groupBy('channel')->map(fn ($group, string $channel) => [
            'channel' => $channel,
            'links' => $group->count(),
            'clicks' => (int) $group->sum(fn ($l) => $stats[$l->id]['clicks'] ?? 0),
            'leads' => (int) $group->sum(fn ($l) => $stats[$l->id]['leads'] ?? 0),
            'qualified' => (int) $group->sum(fn ($l) => $stats[$l->id]['qualified'] ?? 0),
            'won' => (int) $group->sum(fn ($l) => $stats[$l->id]['won'] ?? 0),
            'revenue' => $money ? round((float) $group->sum(fn ($l) => $stats[$l->id]['revenue'] ?? 0), 2) : null,
        ])->sortByDesc('leads')->values()->all();

        // A customer nobody can place is the first thing a marketer should look at: it is counted, never dropped.
        $unattributed = Lead::query()->reorder()->where('project_id', $project->id)->whereBetween('leads.created_at', [$from, $to])->whereNull('first_touch_link_id')->count();

        return ['channels' => $channels, 'unattributed' => $unattributed];
    }

    /** @return list<array<string,mixed>> */
    private function campaigns($user, Project $project, $from, $to, bool $money): array
    {
        $report = (new CampaignRevenue($user))->build($from, $to, $project->id);

        return collect($report['campaigns'])->take(10)->map(fn (array $c) => [
            'id' => $c['id'], 'name' => $c['name'], 'platform' => $c['platform'], 'leads' => $c['leads'], 'qualified' => $c['qualified'], 'interested' => $c['interested'],
            'reservations' => $c['reservations'], 'won' => $c['won'],
            'spend' => $money ? ($c['spend'] ?? null) : null, 'revenue' => $money ? ($c['revenue'] ?? null) : null,
        ])->values()->all();
    }

    /** @return list<array<string,mixed>> */
    private function events(Project $project): array
    {
        return TrackingEvent::query()->where('project_id', $project->id)->with('link:id,name')->latest('occurred_at')->latest('id')->limit(20)->get()
            ->map(fn (TrackingEvent $e) => [
                'event' => EventType::from($e->event_type)->canonical(), 'at' => $e->occurred_at->toIso8601String(), 'link' => $e->link?->name, 'device' => $e->device,
                'lead_id' => $e->lead_id ? Lead::query()->whereKey($e->lead_id)->value('uuid') : null,
            ])->all();
    }

    /**
     * @param  Collection<int,TrackingLink>  $links
     * @param  Collection<int,array<string,mixed>>  $healths
     * @return array<string,mixed>
     */
    private function diagnostics(Project $project, $links, $healths): array
    {
        $counts = ['healthy' => 0, 'warning' => 0, 'critical' => 0];
        $issues = [];

        foreach ($links as $link) {
            $h = $healths[$link->id] ?? null;

            if ($h === null) {
                continue;
            }

            $counts[$h['status']]++;

            foreach ($h['checks'] as $check) {
                if ($check['state'] !== 'ok') {
                    $issues[] = ['link' => $link->name, 'link_id' => $link->uuid, 'check' => $check['key'], 'state' => $check['state']];
                }
            }
        }

        usort($issues, fn ($a, $b) => ($a['state'] === 'critical' ? 0 : 1) <=> ($b['state'] === 'critical' ? 0 : 1));

        return [
            'links' => $counts,
            'issues' => array_slice($issues, 0, 12),
            'destinations' => TrackingDestination::query()->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $project->id))->get()
                ->map(fn (TrackingDestination $d) => ['id' => $d->uuid, 'kind' => $d->kind, 'name' => $d->name, 'is_enabled' => (bool) $d->is_enabled, 'readiness' => $d->readiness(), 'last_error' => $d->last_error])->values()->all(),
        ];
    }
}
