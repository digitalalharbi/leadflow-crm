<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Permissions;
use App\Domain\Sales\RepPerformance;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Project;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * How each sales rep is doing — response time, contact, interest, visit, reservation and close
 * rates, revenue, how often they answered inside the window, how many follow-ups they finished —
 * and a ranking that is fair to the leads each was given. For the people who manage the team.
 */
class RepPerformanceController extends Controller
{
    public function __invoke(Request $request, RepPerformance $performance)
    {
        $this->authorize('export', Lead::class);
        // People are named here: it is the team's page, never an outside window's.
        abort_if($request->user()->isExternal(), 403);

        $data = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365'], 'project_id' => ['nullable', 'uuid']]);
        $days = (int) ($data['days'] ?? 30);
        $to = CarbonImmutable::today()->endOfDay();
        $project = isset($data['project_id']) ? Project::where('uuid', $data['project_id'])->firstOrFail() : null;

        return ApiResponse::success($performance->build(
            $to->subDays($days - 1)->startOfDay(), $to, $project?->id,
            Permissions::has($request->user(), 'revenue.view'),
        ) + ['days' => $days]);
    }
}
