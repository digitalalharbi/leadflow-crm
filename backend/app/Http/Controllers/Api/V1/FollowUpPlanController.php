<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Automation\FollowUpPlanner;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The follow-up plan on a lead, and the three things a person can do to it.
 *
 * Pause holds everything automatic until somebody resumes it, Reschedule moves
 * what is waiting to a time they choose, and Stop ends it. All three act on the
 * plan; none of them messages the customer.
 */
class FollowUpPlanController extends Controller
{
    public function __construct(private FollowUpPlanner $planner) {}

    public function show(string $uuid)
    {
        $lead = $this->lead($uuid, 'view');

        return ApiResponse::success(['follow_up' => $this->planner->view($lead)]);
    }

    public function pause(Request $request, string $uuid)
    {
        $lead = $this->lead($uuid);

        return ApiResponse::success(['follow_up' => $this->planner->pause($lead, $request->user()->name)]);
    }

    public function resume(string $uuid)
    {
        $lead = $this->lead($uuid);

        return ApiResponse::success(['follow_up' => $this->planner->resume($lead)]);
    }

    public function stop(Request $request, string $uuid)
    {
        $lead = $this->lead($uuid);

        return ApiResponse::success(['follow_up' => $this->planner->stop($lead, 'stopped_by_user', $request->user()->name)]);
    }

    public function reschedule(Request $request, string $uuid)
    {
        $data = $request->validate(['at' => ['required', 'date', 'after:now']]);
        $lead = $this->lead($uuid);

        try {
            $plan = $this->planner->reschedule($lead, Carbon::parse($data['at']));
        } catch (\DomainException) {
            return ApiResponse::error('لا توجد خطوات منتظرة لإعادة جدولتها.', null, 422);
        }

        return ApiResponse::success(['follow_up' => $plan]);
    }

    private function lead(string $uuid, string $ability = 'update'): Lead
    {
        $lead = Lead::where('uuid', $uuid)->firstOrFail();
        $this->authorize($ability, $lead);

        return $lead;
    }
}
