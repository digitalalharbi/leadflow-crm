<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Permissions;
use App\Domain\Analytics\SourceAttribution;
use App\Domain\Campaigns\AdPlatform;
use App\Domain\Campaigns\AdPlatformRegistry;
use App\Domain\Campaigns\CampaignMetrics;
use App\Domain\Campaigns\CampaignOperations;
use App\Domain\Campaigns\CampaignQuality;
use App\Domain\Campaigns\CampaignRevenue;
use App\Domain\Campaigns\CampaignSyncer;
use App\Domain\Campaigns\PlatformVerification;
use App\Domain\Campaigns\QualityBreakdown;
use App\Domain\Campaigns\SelfApproval;
use App\Domain\Conversions\ConversionHealth;
use App\Domain\Funnel\FunnelFilters;
use App\Domain\Funnel\RealEstateFunnel;
use App\Http\Controllers\Controller;
use App\Models\ActionProposal;
use App\Models\AdAccount;
use App\Models\AdConnection;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Project;
use App\Models\TenantSetting;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Campaigns, read side: the list with what each cost and brought, the platforms
 * and their honest states, and a manual "sync now".
 */
class CampaignController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function index(Request $request)
    {
        $this->authorize('export', Lead::class);

        [$from, $to] = $this->window($request);
        $metrics = new CampaignMetrics($request->user(), $from, $to);

        $rows = $metrics->rows([
            'platform' => in_array($request->input('platform'), AdPlatform::values(), true) ? $request->input('platform') : null,
            'status' => $request->input('status'),
            'project_id' => $request->filled('project_id')
                ? Project::query()->where('uuid', $request->input('project_id'))->value('id')
                : null,
        ]);

        return ApiResponse::success([
            'campaigns' => $rows,
            'totals' => $metrics->totals($rows),
            'currency' => $this->context->get()?->settings?->currency ?? 'SAR',
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    /**
     * The operations table: every campaign with what it costs and what it brought, how good it is and
     * why, what is waiting for approval on it, and what can be done to it on its platform.
     */
    public function operations(Request $request)
    {
        $this->authorize('export', Lead::class);

        [$from, $to] = $this->window($request);
        $metrics = new CampaignMetrics($request->user(), $from, $to);

        $rows = $metrics->rows([
            'platform' => in_array($request->input('platform'), AdPlatform::values(), true) ? $request->input('platform') : null,
            'status' => $request->input('status'),
            'project_id' => $request->filled('project_id') ? Project::query()->where('uuid', $request->input('project_id'))->value('id') : null,
        ]);
        $totals = $metrics->totals($rows);
        $rows = CampaignQuality::attach($rows, $totals, $from, $to);

        $pending = ActionProposal::query()->where('status', ActionProposal::PENDING)->whereNotNull('campaign_id')
            ->selectRaw('campaign_id, count(*) as n')->groupBy('campaign_id')->pluck('n', 'campaign_id');
        $campaigns = Campaign::query()->whereIn('uuid', array_column($rows, 'id'))->get(['id', 'uuid', 'project_id'])->keyBy('uuid');
        $registry = app(AdPlatformRegistry::class);
        $settings = TenantSetting::query()->first();
        $projects = Project::query()->whereIn('id', $campaigns->pluck('project_id')->filter()->unique()->all())->get()->keyBy('id');
        $workspaceSelf = SelfApproval::for($settings);

        return ApiResponse::success([
            'campaigns' => array_map(function (array $row) use ($pending, $campaigns, $registry, $settings, $projects, $request) {
                $actions = $registry->get($row['platform'])->actions();
                $campaign = $campaigns[$row['id']] ?? null;
                $can = array_values(array_filter([
                    in_array('pause', $actions, true) ? 'pause' : null, in_array('resume', $actions, true) ? 'resume' : null,
                    in_array('pause', $actions, true) && in_array('resume', $actions, true) ? 'set_status' : null,
                    in_array('update_budget', $actions, true) ? 'set_budget' : null, 'sync',
                ]));

                return $row + [
                    'pending_proposals' => (int) ($pending[$campaign?->id ?? 0] ?? 0),
                    // What this platform lets us do to it; sync reads, so it is always there.
                    'can' => $can,
                    // Which of those this person's own change runs at once, by the self-approve setting in force here.
                    'instant' => SelfApproval::for($settings, $campaign?->project_id ? $projects[$campaign->project_id] ?? null : null)->instant($request->user(), $can),
                ];
            }, $rows),
            'self_approval' => ['enabled' => $workspaceSelf->enabled(), 'actions' => $workspaceSelf->actions(), 'max_budget_change_pct' => $workspaceSelf->maxBudgetChangePct()],
            'totals' => $totals,
            'classes' => array_count_values(array_filter(array_map(fn ($r) => $r['quality']['class'], $rows))),
            'sandbox' => $registry->sandboxed(),
            'currency' => $this->context->get()?->settings?->currency ?? 'SAR',
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    /** One change to one campaign. It is proposed, not made: see CampaignOperations. */
    public function operate(Request $request, string $uuid)
    {
        $this->authorize('export', Lead::class);

        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(CampaignOperations::ACTIONS)],
            'reason' => ['required_unless:action,sync', 'nullable', 'string', 'max:2000'],
            'status' => ['required_if:action,set_status', 'nullable', 'in:active,paused'],
            'daily_budget' => ['required_if:action,set_budget', 'nullable', 'numeric', 'min:0.01', 'max:100000000'],
        ]);

        $campaign = Campaign::query()->where('uuid', $uuid)->firstOrFail();
        $result = app(CampaignOperations::class)->operate($request->user(), $campaign, $data['action'], $data);

        return $result['kind'] === 'proposal' ? ApiResponse::created($result) : ApiResponse::success($result);
    }

    /** "View tracking health" for one campaign. */
    public function trackingHealth(Request $request, string $uuid)
    {
        $this->authorize('export', Lead::class);

        return ApiResponse::success(app(CampaignOperations::class)->trackingHealth(Campaign::query()->where('uuid', $uuid)->firstOrFail()));
    }

    /** From the money spent to the money earned: funnel, table, heatmap, trend, mix, comparison, rankings. */
    public function revenue(Request $request)
    {
        $this->authorize('export', Lead::class);

        [$from, $to] = $this->window($request);
        $projectId = $request->filled('project_id') ? Project::query()->where('uuid', $request->input('project_id'))->value('id') : null;
        abort_if($request->filled('project_id') && $projectId === null, 404, 'Resource not found.');

        $platform = in_array($request->input('platform'), AdPlatform::values(), true) ? $request->input('platform') : null;
        $report = (new CampaignRevenue($request->user()))->build($from, $to, $projectId, $platform);

        // The same funnel as everywhere, with what each stage cost when the spend is known.
        $funnel = app(RealEstateFunnel::class)->build(new FunnelFilters($from, $to, $projectId ? [$projectId] : [], $platform), $request->user(), Permissions::has($request->user(), 'revenue.view'));
        $spend = $report['totals']['spend'] ?? null;
        $funnel['stages'] = array_map(fn (array $stage) => $spend !== null && $spend > 0 && in_array($stage['key'], ['interest', 'engagement', 'booking', 'decision'], true) && ($stage['count'] ?? 0) > 0
            ? $stage + ['cost' => round($spend / $stage['count'], 2)] : $stage, $funnel['stages']);

        return ApiResponse::success($report + ['real_estate_funnel' => $funnel, 'currency' => $this->context->get()?->settings?->currency ?? 'SAR']);
    }

    public function show(Request $request, string $uuid)
    {
        $this->authorize('export', Lead::class);

        $campaign = Campaign::query()->where('uuid', $uuid)->firstOrFail();
        [$from, $to] = $this->window($request);

        return ApiResponse::success((new CampaignMetrics($request->user(), $from, $to))->detail($campaign) + [
            'currency' => $this->context->get()?->settings?->currency ?? 'SAR',
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    /**
     * Marketing and sales in one table, grouped the way the business asks:
     * by platform, by project, or by company. Each group is the sum of its
     * campaigns, so drilling down from a company lands on the same numbers.
     */
    public function marketingSales(Request $request)
    {
        $this->authorize('export', Lead::class);

        [$from, $to] = $this->window($request);
        $metrics = new CampaignMetrics($request->user(), $from, $to);
        $rows = collect($metrics->rows());
        $group = in_array($request->input('group'), ['platform', 'project', 'company'], true) ? $request->input('group') : 'platform';

        $projects = Project::query()->with('company:id,uuid,name')->get(['id', 'uuid', 'name', 'company_id'])->keyBy('uuid');

        $key = fn (array $r) => match ($group) {
            'platform' => [$r['platform'], null],
            'project' => [$r['project']['id'] ?? '—', $r['project']['name'] ?? null],
            'company' => [
                $projects->get($r['project']['id'] ?? '')?->company?->uuid ?? '—',
                $projects->get($r['project']['id'] ?? '')?->company?->name,
            ],
        };

        $groups = $rows->groupBy(fn ($r) => $key($r)[0])->map(function ($g, $id) use ($metrics, $key) {
            $totals = $metrics->totals($g->all());

            return ['key' => (string) $id, 'name' => $key($g->first())[1]] + $totals;
        })->sortByDesc('qualified')->values()->all();

        return ApiResponse::success([
            'group' => $group,
            'rows' => $groups,
            'totals' => $metrics->totals($rows->all()),
            // Two attributions, each named: campaign attribution needs synced campaigns; source attribution is every
            // lead by the platform it came from, and is what a workspace without ad accounts reads.
            'attribution' => [
                'synced_campaigns' => $rows->count(),
                'campaign_basis_available' => $rows->isNotEmpty(),
            ],
            'source_attribution' => SourceAttribution::between($request->user(), $from, $to),
            'currency' => $this->context->get()?->settings?->currency ?? 'SAR',
        ]);
    }

    /** The full chain, from spend to revenue, at whichever level was asked for. */
    public function quality(Request $request)
    {
        $this->authorize('export', Lead::class);

        [$from, $to] = $this->window($request);
        $level = in_array($request->input('level'), QualityBreakdown::LEVELS, true) ? $request->input('level') : 'platform';

        return ApiResponse::success([
            'level' => $level,
            'rows' => (new QualityBreakdown($request->user(), $from, $to))->rows($level),
            'currency' => $this->context->get()?->settings?->currency ?? 'SAR',
        ]);
    }

    public function conversionHealth(Request $request, ConversionHealth $health)
    {
        $this->authorize('export', Lead::class);

        return ApiResponse::success(['platforms' => $health->summary()]);
    }

    /**
     * Every platform, and what is true of it right now.
     *
     * `built` is whether an adapter exists; `connected` is whether the workspace
     * has supplied what it needs; the two are different and a platform that is
     * neither must never read as available.
     */
    public function platforms(Request $request)
    {
        $this->authorize('export', Lead::class);

        $registry = app(AdPlatformRegistry::class);
        $verification = app(PlatformVerification::class);
        $accounts = AdAccount::query()->get()->groupBy('platform');

        return ApiResponse::success([
            'platforms' => collect(AdPlatform::cases())->map(function (AdPlatform $platform) use ($registry, $verification, $accounts) {
                $adapter = $registry->get($platform->value);
                $connection = AdConnection::for($platform->value);
                $mine = $accounts->get($platform->value, collect());

                return [
                    'key' => $platform->value,
                    'built' => $adapter->isImplemented(),
                    'connected' => $adapter->isConfigured($connection),
                    'state' => $verification->state($adapter, $connection),
                    'actions' => $adapter->actions(),
                    'capabilities' => $adapter->capabilities(),
                    'accounts' => $mine->count(),
                    'last_synced_at' => ($connection?->last_sync_ok_at ?? $mine->max('last_synced_at'))?->toIso8601String(),
                    'last_error' => $connection?->last_error ?? $mine->pluck('last_error')->filter()->first(),
                ];
            })->values()->all(),
        ]);
    }

    /** Sync now, for the workspace's own connected accounts. */
    public function sync(Request $request)
    {
        $this->authorize('export', Lead::class);

        $syncer = app(CampaignSyncer::class);
        $totals = ['accounts' => 0, 'campaigns' => 0, 'ads' => 0, 'snapshots' => 0];
        $errors = [];

        foreach (AdConnection::active() as $connection) {
            $result = $syncer->sync($connection, 7);

            foreach (array_keys($totals) as $key) {
                $totals[$key] += $result[$key];
            }

            if ($result['error'] !== null) {
                $errors[] = ['platform' => $connection->platform, 'message' => $result['error']];
            }
        }

        return ApiResponse::success($totals + ['errors' => $errors]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function window(Request $request): array
    {
        $to = $request->filled('date_to') ? CarbonImmutable::parse($request->input('date_to')) : CarbonImmutable::today();
        $days = min(max((int) $request->input('days', 30), 1), 365);
        $from = $request->filled('date_from') ? CarbonImmutable::parse($request->input('date_from')) : $to->subDays($days - 1);

        return [$from->startOfDay(), $to->endOfDay()];
    }
}
