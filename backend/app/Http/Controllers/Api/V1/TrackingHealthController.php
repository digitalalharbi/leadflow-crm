<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tracking\TrackingHealth;
use App\Http\Controllers\Controller;
use App\Models\TrackingLink;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** Is tracking working, per platform — for the people who read reports and spend the budget. */
class TrackingHealthController extends Controller
{
    public function __invoke(Request $request, TrackingHealth $health)
    {
        $this->authorize('viewAny', TrackingLink::class);
        abort_unless($request->user()->seesAllLeads(), 403);

        $days = in_array((int) $request->input('days', 7), [1, 7, 14, 30], true) ? (int) $request->input('days', 7) : 7;

        return ApiResponse::success($health->build($days));
    }
}
