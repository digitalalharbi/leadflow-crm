<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientRule;
use App\Models\Lead;
use App\Models\Project;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** The client's targets and limits — the workspace's defaults, or one project's own. */
class ClientRuleController extends Controller
{
    public function show(Request $request)
    {
        $this->authorize('export', Lead::class);

        $projectId = $this->projectId($request);
        $own = ClientRule::query()->where('project_id', $projectId)->first();

        return ApiResponse::success(['rules' => $this->present($own ?? ClientRule::for($projectId)), 'inherited' => $own === null]);
    }

    public function update(Request $request)
    {
        $this->authorize('export', Lead::class);

        $data = $request->validate([
            'target_cpl_minor' => ['nullable', 'integer', 'min:0'],
            'target_cpql_minor' => ['nullable', 'integer', 'min:0'],
            'target_cpa_minor' => ['nullable', 'integer', 'min:0'],
            'target_roas' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'min_spend_to_judge_minor' => ['nullable', 'integer', 'min:0'],
            'daily_budget_ceiling_minor' => ['nullable', 'integer', 'min:0'],
            'max_budget_change_pct' => ['required', 'integer', 'min:1', 'max:100'],
            'blackout_days' => ['nullable', 'array', 'max:60'],
            'blackout_days.*' => ['string', 'max:16', 'regex:/^(\d{4}-\d{2}-\d{2}|monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/i'],
            'forbidden_words' => ['nullable', 'array', 'max:200'],
            'forbidden_words.*' => ['string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $rules = ClientRule::query()->firstOrNew(['project_id' => $this->projectId($request)]);
        $rules->fill($data + ['updated_by' => $request->user()->id])->save();

        return ApiResponse::success(['rules' => $this->present($rules), 'inherited' => false]);
    }

    private function projectId(Request $request): ?int
    {
        return $request->filled('project_id')
            ? Project::query()->where('uuid', $request->input('project_id'))->firstOrFail()->id
            : null;
    }

    private function present(ClientRule $r): array
    {
        return $r->only([
            'target_cpl_minor', 'target_cpql_minor', 'target_cpa_minor', 'target_roas', 'min_spend_to_judge_minor',
            'daily_budget_ceiling_minor', 'max_budget_change_pct', 'blackout_days', 'forbidden_words', 'notes',
        ]);
    }
}
