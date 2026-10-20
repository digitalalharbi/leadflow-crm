<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tracking\Attribution;
use App\Domain\Tracking\AttributionReport;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Project;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Who gets the credit — and the same question under every model, so they can be compared. */
class AttributionController extends Controller
{
    public function __invoke(Request $request, AttributionReport $report)
    {
        $this->authorize('viewAny', Lead::class);
        abort_unless($request->user()->seesAllLeads(), 403);

        $f = $request->validate([
            'model' => ['nullable', Rule::in(Attribution::MODELS)],
            'dimension' => ['nullable', Rule::in(AttributionReport::DIMENSIONS)],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'project_id' => ['nullable', 'uuid'],
        ]);

        $days = (int) ($f['days'] ?? 30);
        $projectId = isset($f['project_id']) ? Project::where('uuid', $f['project_id'])->value('id') : null;
        abort_if(isset($f['project_id']) && $projectId === null, 404, 'Resource not found.');

        return ApiResponse::success($report->build(
            $f['model'] ?? 'last', $f['dimension'] ?? 'channel', now()->subDays($days)->startOfDay(), now(), $projectId,
        ) + ['models' => Attribution::MODELS, 'dimensions' => AttributionReport::DIMENSIONS, 'days' => $days]);
    }
}
