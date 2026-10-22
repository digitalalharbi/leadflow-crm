<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeadActivity;
use App\Models\Project;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * What has happened across one project's leads, newest first.
 *
 * A lead's own timeline answers "what happened to this person". This answers
 * the question a project manager actually brings to a project: what has the
 * team been doing here, and has anything happened at all.
 */
class ProjectActivityController extends Controller
{
    private const LIMIT = 40;

    public function __invoke(Request $request, string $uuid)
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $project);

        /*
         * Through the lead relation, so everything the user could not open a
         * lead to see stays out of the feed. A project feed that listed another
         * rep's leads by name would be a side door around the lead list's own
         * visibility rules.
         */
        $activities = LeadActivity::query()
            ->whereHas('lead', fn ($lead) => $lead->where('project_id', $project->id))
            ->with(['lead:id,uuid,full_name', 'user:id,uuid,name'])
            ->latest('id')
            ->limit(self::LIMIT)
            ->get();

        return ApiResponse::success([
            'activities' => $activities->map(fn (LeadActivity $activity) => [
                'id' => $activity->id,
                'type' => $activity->type->value,
                'description' => $activity->description,
                'at' => $activity->created_at?->toIso8601String(),
                'lead' => $activity->lead ? [
                    'id' => $activity->lead->uuid,
                    'name' => $activity->lead->full_name,
                ] : null,
                'by' => $activity->user?->name,
                // Said, not implied: a rule's action and a rep's are different
                // kinds of fact and the feed should not blur them.
                'automated' => ($activity->meta['by'] ?? null) === 'automation'
                    || ($activity->meta['via'] ?? null) === 'telephony',
            ])->values()->all(),
        ]);
    }
}
