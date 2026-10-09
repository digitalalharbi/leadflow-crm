<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\RepositoryActivity;
use App\Models\RepositoryDeployment;
use App\Models\RepositoryPullRequest;
use App\Models\RepositoryRelease;
use App\Models\RepositoryWorkflowRun;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * What a project manager needs to know about the code, on one screen.
 *
 * Not a GitHub dashboard. The questions this answers are the five somebody
 * running a project actually asks — what changed, who merged it, is the build
 * green, did it reach the server, what version is live — and anything that does
 * not answer one of them is noise that makes the five harder to find.
 *
 * Every figure comes from our own tables, written by a webhook or a sweep.
 * Opening this screen never calls GitHub: a page that spent API budget to
 * render would be the first thing to exhaust the budget everything else needs.
 */
class ProjectDevelopmentController extends Controller
{
    public function __invoke(Request $request, string $uuid)
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $project);

        $repository = ProjectRepository::where('project_id', $project->id)->first();

        if (! $repository) {
            return ApiResponse::success(['repository' => null]);
        }

        return ApiResponse::success([
            'repository' => [
                'id' => $repository->uuid,
                'full_name' => $repository->repository_full_name,
                'url' => $repository->webUrl(),
                'default_branch' => $repository->default_branch,
                'develop_branch' => $repository->develop_branch,
                'production_branch' => $repository->production_branch,
                'readiness' => $repository->readiness(),
                'last_webhook_at' => $repository->last_webhook_at?->toIso8601String(),
                'last_sync_at' => $repository->last_sync_at?->toIso8601String(),
            ],
            'pull_requests' => $this->pullRequests($repository),
            'ci' => $this->ci($repository),
            'deployments' => $this->deployments($repository),
            'release' => $this->release($repository),
            'activity' => $this->activity($repository),
        ]);
    }

    /**
     * Open first, then the most recently closed.
     *
     * A manager scanning this is looking for what is stuck, so the readiness
     * word is computed here rather than left to the interface to infer from
     * four separate fields and get subtly wrong.
     */
    private function pullRequests(ProjectRepository $repository): array
    {
        return RepositoryPullRequest::where('project_repository_id', $repository->id)
            ->orderByRaw("case when state = 'open' then 0 else 1 end")
            ->orderByDesc('updated_at_github')
            ->limit(25)
            ->get()
            ->map(fn (RepositoryPullRequest $pr) => [
                'number' => $pr->number,
                'title' => $pr->title,
                'author' => $pr->author_login,
                'head_branch' => $pr->head_branch,
                'base_branch' => $pr->base_branch,
                'state' => $pr->state,
                'draft' => $pr->draft,
                'checks' => $pr->checks_state,
                'review' => $pr->review_state,
                'readiness' => $pr->readiness(),
                'url' => $pr->html_url,
                'opened_at' => $pr->opened_at?->toIso8601String(),
                'merged_at' => $pr->merged_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * The latest run for each environment the project maps, plus the newest
     * run overall for a repository that maps neither.
     */
    private function ci(ProjectRepository $repository): array
    {
        $latest = fn (?string $branch) => $branch === null ? null : RepositoryWorkflowRun::query()
            ->where('project_repository_id', $repository->id)
            ->where('branch', $branch)
            ->orderByDesc('run_id')
            ->first();

        $present = fn (?RepositoryWorkflowRun $run) => $run === null ? null : [
            'name' => $run->name,
            'branch' => $run->branch,
            'outcome' => $run->outcome(),
            'conclusion' => $run->conclusion,
            'commit_sha' => $run->commit_sha,
            'url' => $run->html_url,
            'completed_at' => $run->completed_at?->toIso8601String(),
        ];

        return [
            'staging' => $present($latest($repository->develop_branch)),
            'production' => $present($latest($repository->production_branch)),
        ];
    }

    /**
     * The newest deployment per environment, with the four states kept apart.
     *
     * `truth` is the word the screen shows, and `reported` is its own state on
     * purpose: a deployment the deployer called a success and nobody verified
     * is exactly what the previous pipeline displayed as "deployed" while
     * nothing had shipped.
     */
    private function deployments(ProjectRepository $repository): array
    {
        return RepositoryDeployment::where('project_repository_id', $repository->id)
            ->orderByDesc('deployment_id')
            ->get()
            ->unique('environment')
            ->map(fn (RepositoryDeployment $deployment) => [
                'environment' => $deployment->environment,
                'state' => $deployment->state,
                'truth' => $deployment->truth(),
                'verified_at' => $deployment->verified_at?->toIso8601String(),
                'commit_sha' => $deployment->commit_sha,
                'ref' => $deployment->ref,
                'url' => $deployment->environment_url,
                'at' => $deployment->updated_at_github?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function release(ProjectRepository $repository): ?array
    {
        $release = RepositoryRelease::where('project_repository_id', $repository->id)
            ->where('draft', false)
            ->orderByDesc('published_at')
            ->first();

        return $release === null ? null : [
            'tag' => $release->tag_name,
            'name' => $release->name,
            'prerelease' => $release->prerelease,
            'author' => $release->author_login,
            'url' => $release->html_url,
            'published_at' => $release->published_at?->toIso8601String(),
        ];
    }

    private function activity(ProjectRepository $repository): array
    {
        return RepositoryActivity::where('project_repository_id', $repository->id)
            ->orderByDesc('happened_at')
            ->limit(30)
            ->get()
            ->map(fn (RepositoryActivity $row) => [
                'id' => $row->uuid,
                'kind' => $row->kind,
                'title' => $row->title,
                'actor' => $row->actor,
                'ref' => $row->ref,
                'commit_sha' => $row->commit_sha ?: null,
                'url' => $row->url,
                'meta' => $row->meta,
                'at' => $row->happened_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
