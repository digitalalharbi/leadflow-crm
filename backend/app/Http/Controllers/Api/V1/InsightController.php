<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Insights\InsightEngine;
use App\Http\Controllers\Controller;
use App\Models\Anomaly;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** What to look at first — a few cards and anything that moved outside its normal range. */
class InsightController extends Controller
{
    public function __construct(private InsightEngine $engine, private AuditLogger $audit) {}

    public function index(Request $request)
    {
        return ApiResponse::success($this->engine->for($request->user()));
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
