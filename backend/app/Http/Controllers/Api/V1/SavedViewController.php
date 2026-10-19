<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Leads\LeadViews;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\SavedView;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** The lists a person keeps for themselves, beside the built-in ones. */
class SavedViewController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Lead::class);

        return ApiResponse::success([
            'views' => SavedView::where('user_id', $request->user()->id)->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (SavedView $view) => $this->shape($view)),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('viewAny', Lead::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'filters' => ['required', 'array'],
        ]);

        // Only what the leads list understands, and a built-in view by its key.
        $filters = array_intersect_key($data['filters'], array_flip(SavedView::FILTERS));
        $view = $filters['view'] ?? null;

        if ($view !== null && ! array_key_exists($view, LeadViews::sql())) {
            unset($filters['view']);
        }

        if ($filters === []) {
            return ApiResponse::error('A saved view needs at least one filter.', null, 422);
        }

        $count = SavedView::where('user_id', $request->user()->id)->count();
        if ($count >= 20) {
            return ApiResponse::error('Twenty saved views is the limit — delete one first.', null, 422);
        }

        $saved = SavedView::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'filters' => $filters,
            'sort_order' => $count,
        ]);

        return ApiResponse::created(['view' => $this->shape($saved)]);
    }

    public function destroy(Request $request, string $uuid)
    {
        $this->authorize('viewAny', Lead::class);

        SavedView::where('uuid', $uuid)->where('user_id', $request->user()->id)->firstOrFail()->delete();

        return ApiResponse::success(['deleted' => true]);
    }

    private function shape(SavedView $view): array
    {
        return ['id' => $view->uuid, 'name' => $view->name, 'filters' => $view->filters];
    }
}
