<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tracking\Attribution;
use App\Domain\Tracking\AttributionReport;
use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\Campaign;
use App\Models\Creative;
use App\Models\Lead;
use App\Models\Partner;
use App\Models\Project;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Who gets the credit — and the same question under every model, so they can be compared. */
class AttributionController extends Controller
{
    private const FILTERS = ['campaign_id' => Campaign::class, 'ad_id' => Ad::class, 'creative_id' => Creative::class, 'partner_id' => Partner::class];

    public function __invoke(Request $request, AttributionReport $report)
    {
        [$from, $to, $days, $projectId, $filters] = $this->scope($request);
        $f = $request->validate(['model' => ['nullable', Rule::in(Attribution::MODELS)], 'dimension' => ['nullable', Rule::in(AttributionReport::DIMENSIONS)]]);

        return ApiResponse::success($report->build($f['model'] ?? 'last', $f['dimension'] ?? 'channel', $from, $to, $projectId, $filters)
            + ['models' => Attribution::MODELS, 'dimensions' => AttributionReport::DIMENSIONS, 'days' => $days]);
    }

    /** The four models side by side, for the same filters. */
    public function compare(Request $request, AttributionReport $report)
    {
        [$from, $to, $days, $projectId, $filters] = $this->scope($request);
        $f = $request->validate(['dimension' => ['nullable', Rule::in(AttributionReport::DIMENSIONS)]]);

        return ApiResponse::success($report->compare($f['dimension'] ?? 'channel', $from, $to, $projectId, $filters) + ['days' => $days]);
    }

    /** What the filters can be set to: the campaigns, ads, creatives, partners and reps there are, and the platforms leads came from. */
    public function options(Request $request)
    {
        $this->authorize('viewAny', Lead::class);
        abort_unless($request->user()->seesAllLeads(), 403);

        $names = fn ($model, array $with = []) => $model::query()->orderBy('name')->limit(300)->get(['uuid', 'name'])->map(fn ($m) => ['id' => $m->uuid, 'name' => $m->name])->all();

        return ApiResponse::success([
            'campaigns' => $names(Campaign::class),
            'ads' => $names(Ad::class),
            'creatives' => Creative::query()->orderBy('id')->limit(300)->get(['uuid', 'name', 'headline'])->map(fn ($c) => ['id' => $c->uuid, 'name' => $c->headline ?: $c->name])->all(),
            'partners' => $names(Partner::class),
            'reps' => User::query()->whereIn('role', ['admin', 'manager', 'sales', 'sales_manager'])->orderBy('name')->get(['uuid', 'name'])->map(fn ($u) => ['id' => $u->uuid, 'name' => $u->name])->all(),
            'platforms' => Lead::query()->reorder()->whereNotNull('platform')->distinct()->orderBy('platform')->pluck('platform')->all(),
        ]);
    }

    /** @return array{0:Carbon,1:Carbon,2:int,3:?int,4:array<string,mixed>} the period, the project, and the filters resolved to ids */
    private function scope(Request $request): array
    {
        $this->authorize('viewAny', Lead::class);
        abort_unless($request->user()->seesAllLeads(), 403);

        $f = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'project_id' => ['nullable', 'uuid'],
            'campaign_id' => ['nullable', 'uuid'], 'ad_id' => ['nullable', 'uuid'], 'creative_id' => ['nullable', 'uuid'], 'partner_id' => ['nullable', 'uuid'],
            'rep_id' => ['nullable', 'uuid'], 'platform' => ['nullable', 'string', 'max:32'],
        ]);

        $days = (int) ($f['days'] ?? 30);
        $projectId = isset($f['project_id']) ? Project::where('uuid', $f['project_id'])->value('id') : null;
        abort_if(isset($f['project_id']) && $projectId === null, 404, 'Resource not found.');

        $filters = [];

        foreach (self::FILTERS as $key => $model) {
            if (isset($f[$key])) {
                $filters[$key] = $model::query()->where('uuid', $f[$key])->value('id') ?? abort(404, 'Resource not found.');
            }
        }

        if (isset($f['rep_id'])) {
            $filters['rep_id'] = User::query()->where('tenant_id', $request->user()->tenant_id)->where('uuid', $f['rep_id'])->value('id') ?? abort(404, 'Resource not found.');
        }

        if (isset($f['platform'])) {
            $filters['platform'] = $f['platform'];
        }

        return [now()->subDays($days)->startOfDay(), now(), $days, $projectId, $filters];
    }
}
