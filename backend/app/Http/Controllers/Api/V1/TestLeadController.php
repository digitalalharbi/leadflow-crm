<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Onboarding\SetupChecklist;
use App\Domain\Onboarding\TestLeadDiagnostic;
use App\Http\Controllers\Controller;
use App\Models\AutomationRun;
use App\Models\Lead;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * "Does any of this actually work for us?"
 *
 * The question every workspace has on its first day, and the one the settings
 * screens cannot answer. This sends a lead down the real pipeline and reports
 * what each stage did with it.
 */
class TestLeadController extends Controller
{
    /** What is still missing before this workspace can run on the product. */
    public function checklist(Request $request, SetupChecklist $checklist)
    {
        // Readable by anyone who can see the whole workspace: a manager being
        // told why leads are not being assigned should not need an admin.
        $this->authorize('viewAny', User::class);

        return ApiResponse::success($checklist->for($request->user()->tenant));
    }

    public function store(Request $request, TestLeadDiagnostic $diagnostic)
    {
        // Running the pipeline assigns a lead to somebody and may notify them,
        // so it belongs to whoever is allowed to set the workspace up.
        $this->authorize('update', $request->user()->tenant);

        return ApiResponse::success($diagnostic->run($request->user()->tenant));
    }

    /**
     * The same report, re-read.
     *
     * Automation runs on a queue, so the interesting half of the trace usually
     * arrives a second or two after the lead does. Without this the workspace
     * sees "not yet" and has no way to look again without creating a second
     * test lead.
     */
    public function show(Request $request, string $uuid)
    {
        $this->authorize('update', $request->user()->tenant);

        $lead = Lead::withTestLeads()->where('uuid', $uuid)->firstOrFail();

        $runs = AutomationRun::where('lead_id', $lead->id)->with('rule:id,uuid,name')->get();

        return ApiResponse::success([
            'lead' => [
                'id' => $lead->uuid,
                'name' => $lead->full_name,
                'status' => $lead->status?->value,
                'score' => $lead->score,
                'assignee' => $lead->assignee?->name,
            ],
            'automation' => $runs->map(fn (AutomationRun $run) => [
                'rule' => $run->rule?->name,
                'outcome' => $run->outcome,
                'reason' => $run->reason,
            ])->values(),
        ]);
    }

    /**
     * Removes the test lead and everything it dragged in.
     *
     * A workspace should be able to prove the setup works and then leave no
     * trace of having done so. The global scope already keeps it out of the
     * numbers; this is for the lead list, where it is visible and would
     * otherwise sit at the top forever.
     */
    public function destroy(Request $request, string $uuid)
    {
        $this->authorize('update', $request->user()->tenant);

        $lead = Lead::withTestLeads()->where('uuid', $uuid)->firstOrFail();

        abort_unless($lead->is_test, 404);

        $lead->forceDelete();

        return ApiResponse::success(['deleted' => true]);
    }
}
