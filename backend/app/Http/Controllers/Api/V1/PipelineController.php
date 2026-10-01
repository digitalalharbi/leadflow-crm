<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeadListResource;
use App\Models\Lead;
use App\Services\Leads\LeadQueryBuilder;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Kanban board data. Each column is capped and reports its true total so the
 * board stays fast for a tenant with 50k leads.
 */
class PipelineController extends Controller
{
    public function __construct(private LeadQueryBuilder $queries) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Lead::class);

        // A column shows about six cards before it scrolls, so fetching fifty was
        // sending a 200KB payload to render a fraction of it. Twenty-five keeps
        // scrolling smooth at roughly half the bytes; the true total is reported
        // separately, so nothing is hidden from the user.
        $limit = min((int) $request->input('per_column', 25), 100);

        // One grouped count for every column, rather than a count per column.
        $totals = $this->queries->build($request, $request->user())
            ->reorder()
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        // One windowed query for the cards, rather than a query per column.
        $ranked = $this->queries->build($request, $request->user())
            ->reorder()
            ->select('leads.id', DB::raw(
                'row_number() over (partition by leads.status order by leads.score desc, leads.created_at desc) as column_rank'
            ));

        $ids = DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('column_rank', '<=', $limit)
            ->pluck('id');

        $leads = Lead::whereIn('id', $ids)
            ->with(['assignee:id,uuid,name', 'project:id,uuid,name'])
            ->orderByDesc('score')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(fn (Lead $lead) => $lead->status->value);

        $columns = collect(LeadStatus::cases())->map(fn (LeadStatus $status) => [
            'status' => $status->value,
            'position' => $status->position(),
            'total' => (int) ($totals[$status->value] ?? 0),
            'leads' => LeadListResource::collection($leads->get($status->value, collect())),
        ])->all();

        return ApiResponse::success(['columns' => $columns]);
    }
}
