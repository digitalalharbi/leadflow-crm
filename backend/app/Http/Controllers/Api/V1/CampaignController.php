<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Campaigns\AdPlatform;
use App\Domain\Campaigns\AdPlatformRegistry;
use App\Domain\Campaigns\CampaignMetrics;
use App\Domain\Campaigns\CampaignSyncer;
use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Project;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

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
        $integrations = Integration::query()->where('is_active', true)->get()->keyBy(fn ($i) => $i->provider?->value);
        $accounts = AdAccount::query()->get()->groupBy('platform');

        return ApiResponse::success([
            'platforms' => collect(AdPlatform::cases())->map(function (AdPlatform $platform) use ($registry, $integrations, $accounts) {
                $adapter = $registry->get($platform->value);
                $integration = $integrations->get($platform->value);
                $mine = $accounts->get($platform->value, collect());

                return [
                    'key' => $platform->value,
                    'built' => $adapter->isImplemented(),
                    'connected' => $adapter->isConfigured($integration),
                    'actions' => $adapter->actions(),
                    'accounts' => $mine->count(),
                    'last_synced_at' => $mine->max('last_synced_at')?->toIso8601String(),
                    'last_error' => $mine->pluck('last_error')->filter()->first(),
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

        foreach (Integration::query()->where('is_active', true)->get() as $integration) {
            $result = $syncer->sync($integration, 7);

            foreach (array_keys($totals) as $key) {
                $totals[$key] += $result[$key];
            }

            if ($result['error'] !== null) {
                $errors[] = ['platform' => $integration->provider?->value, 'message' => $result['error']];
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
