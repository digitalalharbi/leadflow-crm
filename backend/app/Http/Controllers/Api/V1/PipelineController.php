<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeadListResource;
use App\Models\Lead;
use App\Services\Leads\LeadQueryBuilder;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

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

        $limit = min((int) $request->input('per_column', 50), 100);
        $columns = [];

        foreach (LeadStatus::cases() as $status) {
            $base = $this->queries->build($request, $request->user())
                ->where('status', $status->value);

            $total = (clone $base)->count();

            $leads = $base->with(['assignee:id,uuid,name', 'project:id,uuid,name'])
                ->reorder()
                ->orderByDesc('score')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            $columns[] = [
                'status' => $status->value,
                'position' => $status->position(),
                'total' => $total,
                'leads' => LeadListResource::collection($leads),
            ];
        }

        return ApiResponse::success(['columns' => $columns]);
    }
}
