<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Permissions;
use App\Domain\Funnel\FunnelRequest;
use App\Domain\Funnel\RealEstateFunnel;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * `GET /funnel` — the real-estate funnel (وعي → اهتمام → تفاعل → حجز معاينة → قرار شراء → إحالة) for whoever asks: their
 * own leads, their own projects, their own workspace. Revenue only for someone who may see revenue.
 */
class FunnelController extends Controller
{
    public function __invoke(Request $request, RealEstateFunnel $funnel)
    {
        $this->authorize('viewAny', Lead::class);
        $user = $request->user();
        // The funnel with filters is an analysis: the people who read analytics. A rep's own funnel is on their dashboard.
        abort_unless(Permissions::has($user, 'analytics.view'), 403);
        $request->validate([
            'range' => ['sometimes', 'in:'.implode(',', FunnelRequest::RANGES)],
            'date_from' => ['sometimes', 'date'], 'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'compare' => ['sometimes', 'in:previous_period,previous_month'],
        ]);

        return ApiResponse::success($funnel->build(FunnelRequest::filters($request, $user), $user, Permissions::has($user, 'revenue.view')));
    }
}
