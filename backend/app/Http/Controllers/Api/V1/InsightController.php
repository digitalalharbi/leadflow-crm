<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Permissions;
use App\Domain\Analytics\OperationsHome;
use App\Domain\Analytics\OpsPulse;
use App\Domain\Insights\InsightEngine;
use App\Domain\Insights\Recommendations;
use App\Http\Controllers\Controller;
use App\Models\Anomaly;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** What to look at first — a few cards and anything that moved outside its normal range. */
class InsightController extends Controller
{
    public function __construct(private InsightEngine $engine, private AuditLogger $audit) {}

    /** The operations home: nine answers for today and four lights for the machinery. */
    public function ops(Request $request, OpsPulse $pulse)
    {
        return ApiResponse::success($pulse->for($request->user()));
    }

    /** The operations home's second half: campaign, tracking and automation health, the sales ranking, the revenue trend, the best source, campaign and partner. */
    public function home(Request $request, OperationsHome $home)
    {
        return ApiResponse::success($home->for($request->user()));
    }

    public function index(Request $request)
    {
        return ApiResponse::success($this->engine->for($request->user()));
    }

    /**
     * From "something moved" to "do this": findings that compare campaigns, partners and reps, each with
     * what it means and what to do, and where to go. Money findings only for those who may see money.
     */
    public function recommendations(Request $request, Recommendations $engine)
    {
        abort_unless($request->user()->seesAllLeads() && ! $request->user()->isExternal(), 403);

        $data = $request->validate(['days' => ['nullable', 'integer', 'min:7', 'max:180']]);
        $days = (int) ($data['days'] ?? 30);
        $to = CarbonImmutable::today()->endOfDay();
        $user = $request->user();

        return ApiResponse::success([
            'items' => $engine->build($to->subDays($days - 1)->startOfDay(), $to, null, Permissions::has($user, 'spend.view'), $user->tenant?->settings?->currency ?? 'SAR'),
            'days' => $days,
        ]);
    }

    /** "I have seen this": it leaves the list, the record stays. */
    public function dismiss(Request $request, string $uuid)
    {
        $anomaly = Anomaly::where('uuid', $uuid)->firstOrFail();
        abort_unless($request->user()->isAdmin() || $request->user()->isManager(), 403);

        $anomaly->forceFill(['dismissed_at' => now()])->save();
        $this->audit->log('anomaly.dismissed', $request->user(), $anomaly, ['kind' => $anomaly->kind, 'subject' => $anomaly->subject]);

        return ApiResponse::success(['dismissed' => true]);
    }
}
