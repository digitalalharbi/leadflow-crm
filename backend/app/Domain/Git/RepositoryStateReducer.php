<?php

namespace App\Domain\Git;

use App\Models\ProjectRepository;
use App\Models\RepositoryActivity;
use App\Models\RepositoryDeployment;
use App\Models\RepositoryPullRequest;
use App\Models\RepositoryRelease;
use App\Models\RepositoryWorkflowRun;
use Illuminate\Support\Carbon;

/**
 * What a delivery means for what مهتم shows.
 *
 * One place, so that a webhook and a reconciliation sweep produce the same row
 * from the same facts — the sweep exists precisely because webhooks get missed,
 * and two code paths would mean the repair wrote something subtly different
 * from the thing it was repairing.
 *
 * Everything is keyed on GitHub's own ids and written with `updateOrCreate`, so
 * a redelivery, a retry and a sweep all converge rather than accumulate.
 *
 * It is deliberately boring. The interesting decisions are two:
 *   - which environment a branch means, which comes from the project's mapping
 *     rather than from a workflow's name;
 *   - what counts as deployed, which is never a green build.
 */
class RepositoryStateReducer
{
    /**
     * Timeline rows this pass created, as opposed to re-wrote.
     *
     * Kept so the caller can tell somebody about them: a redelivery and a sweep
     * both re-write rows they have seen, and neither is news.
     *
     * @var array<int,RepositoryActivity>
     */
    private array $created = [];

    /**
     * @return array{handled:bool,summary:array,created:array<int,RepositoryActivity>}
     */
    public function apply(ProjectRepository $repository, string $event, array $payload): array
    {
        $this->created = [];

        $result = match ($event) {
            'pull_request' => $this->pullRequest($repository, $payload),
            'pull_request_review' => $this->review($repository, $payload),
            'workflow_run' => $this->workflowRun($repository, $payload),
            'check_suite', 'check_run' => $this->checks($repository, $event, $payload),
            'deployment' => $this->deployment($repository, $payload),
            'deployment_status' => $this->deploymentStatus($repository, $payload),
            'release' => $this->release($repository, $payload),
            'push' => $this->push($repository, $payload),
            default => ['handled' => false, 'summary' => ['event' => $event]],
        };

        return $result + ['created' => $this->created];
    }

    // ------------------------------------------------------------------ PRs

    private function pullRequest(ProjectRepository $repository, array $payload): array
    {
        $pr = $payload['pull_request'] ?? [];
        $number = (int) ($pr['number'] ?? 0);

        if ($number === 0) {
            return ['handled' => false, 'summary' => []];
        }

        $merged = (bool) ($pr['merged'] ?? false);
        $state = match (true) {
            $merged => 'merged',
            ($pr['state'] ?? 'open') === 'closed' => 'closed',
            default => 'open',
        };

        $row = RepositoryPullRequest::updateOrCreate(
            ['project_repository_id' => $repository->id, 'number' => $number],
            [
                'title' => (string) ($pr['title'] ?? ''),
                'author_login' => $pr['user']['login'] ?? null,
                'head_branch' => (string) ($pr['head']['ref'] ?? ''),
                'base_branch' => (string) ($pr['base']['ref'] ?? ''),
                'state' => $state,
                'draft' => (bool) ($pr['draft'] ?? false),
                'mergeable_state' => $pr['mergeable_state'] ?? null,
                'html_url' => (string) ($pr['html_url'] ?? ''),
                'opened_at' => $this->time($pr['created_at'] ?? null),
                'updated_at_github' => $this->time($pr['updated_at'] ?? null),
                'merged_at' => $this->time($pr['merged_at'] ?? null),
                'merged_by' => $pr['merged_by']['login'] ?? null,
            ],
        );

        $action = $payload['action'] ?? '';

        if ($action === 'opened' || $action === 'reopened') {
            $this->remember($repository, 'pr_opened', "#{$number} {$row->title}", [
                'actor' => $row->author_login,
                'ref' => (string) $number,
                'url' => $row->html_url,
                'happened_at' => $row->opened_at ?? now(),
            ]);
        }

        if ($action === 'closed') {
            $this->remember($repository, $merged ? 'pr_merged' : 'pr_closed', "#{$number} {$row->title}", [
                'actor' => $merged ? ($row->merged_by ?? $row->author_login) : $row->author_login,
                'ref' => (string) $number,
                'commit_sha' => $pr['merge_commit_sha'] ?? '',
                'url' => $row->html_url,
                'happened_at' => $row->merged_at ?? now(),
                'meta' => ['base' => $row->base_branch, 'head' => $row->head_branch],
            ]);
        }

        return ['handled' => true, 'summary' => ['pull_request' => $number, 'state' => $state]];
    }

    /**
     * A review changes whether a PR is ready, not what it is.
     *
     * Only the latest verdict is kept: the question on the list is "can this
     * merge", and a changes-requested from last week that has since been
     * approved is not an answer to it.
     */
    private function review(ProjectRepository $repository, array $payload): array
    {
        $number = (int) ($payload['pull_request']['number'] ?? 0);
        $state = strtolower((string) ($payload['review']['state'] ?? ''));

        $row = RepositoryPullRequest::where('project_repository_id', $repository->id)
            ->where('number', $number)
            ->first();

        if (! $row || $state === '') {
            return ['handled' => false, 'summary' => ['pull_request' => $number]];
        }

        $row->forceFill(['review_state' => match ($state) {
            'approved' => 'approved',
            'changes_requested' => 'changes_requested',
            // A comment is not a verdict, and treating it as one would make a
            // "looks good, but…" read as an approval on the list.
            default => $row->review_state,
        }])->save();

        return ['handled' => true, 'summary' => ['pull_request' => $number, 'review' => $state]];
    }

    // ------------------------------------------------------------------- CI

    private function workflowRun(ProjectRepository $repository, array $payload): array
    {
        $run = $payload['workflow_run'] ?? [];
        $runId = (int) ($run['id'] ?? 0);

        if ($runId === 0) {
            return ['handled' => false, 'summary' => []];
        }

        $branch = $run['head_branch'] ?? null;

        $row = RepositoryWorkflowRun::updateOrCreate(
            ['project_repository_id' => $repository->id, 'run_id' => $runId],
            [
                'name' => (string) ($run['name'] ?? 'workflow'),
                'event' => $run['event'] ?? null,
                'branch' => $branch,
                'commit_sha' => $run['head_sha'] ?? null,
                'status' => (string) ($run['status'] ?? 'queued'),
                'conclusion' => $run['conclusion'] ?? null,
                /*
                 * The environment comes from the project's branch mapping, not
                 * from the workflow's name. A workflow called "Deploy" that ran
                 * on a feature branch deployed nothing, and a product that read
                 * the name would say it had.
                 */
                'environment' => $repository->environmentFor($branch),
                'html_url' => (string) ($run['html_url'] ?? ''),
                'started_at' => $this->time($run['run_started_at'] ?? $run['created_at'] ?? null),
                'completed_at' => $this->time($run['updated_at'] ?? null),
            ],
        );

        if ($row->status === 'completed') {
            $outcome = $row->outcome();

            // A skipped run is not a passing one. This is the exact shape of the
            // deploy pipeline that reported green while shipping nothing.
            if (in_array($outcome, ['passed', 'failed'], true)) {
                $this->remember($repository, $outcome === 'passed' ? 'ci_passed' : 'ci_failed', $row->name, [
                    'ref' => $branch,
                    'commit_sha' => $row->commit_sha ?? '',
                    'url' => $row->html_url,
                    'happened_at' => $row->completed_at ?? now(),
                    'meta' => ['conclusion' => $row->conclusion, 'environment' => $row->environment],
                ]);
            }

            $this->refreshChecksForBranch($repository, $branch);
        }

        return ['handled' => true, 'summary' => ['workflow_run' => $runId, 'outcome' => $row->outcome()]];
    }

    /**
     * A check suite tells us a branch's build changed without telling us which
     * run it was, so the PR's own state is recomputed from the runs we have.
     */
    private function checks(ProjectRepository $repository, string $event, array $payload): array
    {
        $branch = $payload['check_suite']['head_branch']
            ?? $payload['check_run']['check_suite']['head_branch']
            ?? null;

        $this->refreshChecksForBranch($repository, $branch);

        return ['handled' => true, 'summary' => [$event => $branch]];
    }

    /**
     * What the open pull requests on this branch should say about their build.
     *
     * Derived from the latest completed run rather than stored from whichever
     * event arrived last: runs for one commit finish in an unpredictable order,
     * and "the last one we heard about" is not "the state of the build".
     */
    private function refreshChecksForBranch(ProjectRepository $repository, ?string $branch): void
    {
        if (blank($branch)) {
            return;
        }

        $runs = RepositoryWorkflowRun::where('project_repository_id', $repository->id)
            ->where('branch', $branch)
            ->orderByDesc('run_id')
            ->limit(20)
            ->get();

        // One row per workflow name: a repository with three workflows is green
        // only when the newest run of each of them is.
        $latest = $runs->unique('name');

        $state = match (true) {
            $latest->isEmpty() => 'unknown',
            $latest->contains(fn (RepositoryWorkflowRun $r) => $r->outcome() === 'failed') => 'failed',
            $latest->contains(fn (RepositoryWorkflowRun $r) => in_array($r->outcome(), ['queued', 'running'], true)) => 'pending',
            $latest->contains(fn (RepositoryWorkflowRun $r) => $r->outcome() === 'passed') => 'passed',
            default => 'unknown',
        };

        RepositoryPullRequest::where('project_repository_id', $repository->id)
            ->where('head_branch', $branch)
            ->where('state', 'open')
            ->update(['checks_state' => $state]);
    }

    // ----------------------------------------------------------- deployment

    private function deployment(ProjectRepository $repository, array $payload): array
    {
        $deployment = $payload['deployment'] ?? [];
        $id = (int) ($deployment['id'] ?? 0);

        if ($id === 0) {
            return ['handled' => false, 'summary' => []];
        }

        RepositoryDeployment::updateOrCreate(
            ['project_repository_id' => $repository->id, 'deployment_id' => $id],
            [
                'environment' => (string) ($deployment['environment'] ?? 'unknown'),
                'ref' => $deployment['ref'] ?? null,
                'commit_sha' => $deployment['sha'] ?? null,
                'description' => $deployment['description'] ?? null,
                'html_url' => $deployment['url'] ?? null,
                'created_at_github' => $this->time($deployment['created_at'] ?? null),
            ],
        );

        return ['handled' => true, 'summary' => ['deployment' => $id]];
    }

    /**
     * The deployer's own report.
     *
     * Recorded as `state`, never as verification: GitHub's `success` means the
     * job that deploys finished without error, and nobody has yet asked the
     * application whether it is answering. The previous pipeline showed exactly
     * this as "deployed" while nothing had shipped.
     */
    private function deploymentStatus(ProjectRepository $repository, array $payload): array
    {
        $deployment = $payload['deployment'] ?? [];
        $status = $payload['deployment_status'] ?? [];
        $id = (int) ($deployment['id'] ?? 0);

        if ($id === 0) {
            return ['handled' => false, 'summary' => []];
        }

        $row = RepositoryDeployment::updateOrCreate(
            ['project_repository_id' => $repository->id, 'deployment_id' => $id],
            [
                'environment' => (string) ($status['environment'] ?? $deployment['environment'] ?? 'unknown'),
                'ref' => $deployment['ref'] ?? null,
                'commit_sha' => $deployment['sha'] ?? null,
                'state' => (string) ($status['state'] ?? 'pending'),
                'description' => $status['description'] ?? null,
                'environment_url' => $status['environment_url'] ?? null,
                'updated_at_github' => $this->time($status['updated_at'] ?? null),
            ],
        );

        if (in_array($row->state, ['success', 'failure', 'error'], true)) {
            $this->remember($repository, $row->state === 'success' ? 'deployed' : 'deploy_failed', $row->environment, [
                'ref' => $row->ref,
                'commit_sha' => $row->commit_sha ?? '',
                'url' => $row->environment_url ?? $row->html_url,
                'happened_at' => $row->updated_at_github ?? now(),
                // Written down so the timeline can say "reported, not verified"
                // rather than quietly implying somebody checked.
                'meta' => ['state' => $row->state, 'verification' => $row->verification_status],
            ]);
        }

        return ['handled' => true, 'summary' => ['deployment' => $id, 'state' => $row->state]];
    }

    // -------------------------------------------------------------- release

    private function release(ProjectRepository $repository, array $payload): array
    {
        $release = $payload['release'] ?? [];
        $id = (int) ($release['id'] ?? 0);

        if ($id === 0 || ($payload['action'] ?? '') !== 'published') {
            return ['handled' => false, 'summary' => ['release' => $id]];
        }

        $row = RepositoryRelease::updateOrCreate(
            ['project_repository_id' => $repository->id, 'release_id' => $id],
            [
                'tag_name' => (string) ($release['tag_name'] ?? ''),
                'name' => $release['name'] ?? null,
                'draft' => (bool) ($release['draft'] ?? false),
                'prerelease' => (bool) ($release['prerelease'] ?? false),
                'commit_sha' => $release['target_commitish'] ?? null,
                'author_login' => $release['author']['login'] ?? null,
                'html_url' => (string) ($release['html_url'] ?? ''),
                'published_at' => $this->time($release['published_at'] ?? null),
            ],
        );

        $this->remember($repository, 'released', $row->name ?: $row->tag_name, [
            'actor' => $row->author_login,
            'ref' => $row->tag_name,
            'url' => $row->html_url,
            'happened_at' => $row->published_at ?? now(),
        ]);

        return ['handled' => true, 'summary' => ['release' => $id, 'tag' => $row->tag_name]];
    }

    // ----------------------------------------------------------------- push

    /**
     * Commits, but only the ones worth a line.
     *
     * A push of forty commits to a feature branch is not forty things a project
     * manager needs to read, so only the mapped branches are recorded — what
     * reached staging and what reached production — and the commit message is
     * trimmed to its first line.
     */
    private function push(ProjectRepository $repository, array $payload): array
    {
        $ref = (string) ($payload['ref'] ?? '');
        $branch = str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : null;

        if ($branch === null || $repository->environmentFor($branch) === null) {
            return ['handled' => true, 'summary' => ['push' => $branch, 'ignored' => 'unmapped_branch']];
        }

        $commits = array_slice($payload['commits'] ?? [], -20);

        foreach ($commits as $commit) {
            $sha = (string) ($commit['id'] ?? '');

            if ($sha === '') {
                continue;
            }

            $this->remember($repository, 'commit', $this->firstLine((string) ($commit['message'] ?? '')), [
                'actor' => $commit['author']['username'] ?? ($commit['author']['name'] ?? null),
                'ref' => $branch,
                'commit_sha' => $sha,
                'url' => $commit['url'] ?? null,
                'happened_at' => $this->time($commit['timestamp'] ?? null) ?? now(),
            ]);
        }

        return ['handled' => true, 'summary' => ['push' => $branch, 'commits' => count($commits)]];
    }

    // ----------------------------------------------------------- the timeline

    /**
     * One row per thing that happened, however many times GitHub mentions it.
     *
     * A merge arrives as `pull_request.closed` and again inside the next
     * `push`; a timeline that showed it twice would be the first thing anybody
     * complained about. The empty string rather than null for an unknown commit
     * is deliberate: Postgres treats NULLs in a unique index as distinct, so a
     * null would defeat the deduplication it is there for.
     */
    private function remember(ProjectRepository $repository, string $kind, string $title, array $attributes): void
    {
        $row = RepositoryActivity::updateOrCreate(
            [
                'project_repository_id' => $repository->id,
                'kind' => $kind,
                'commit_sha' => (string) ($attributes['commit_sha'] ?? ''),
                'ref' => $attributes['ref'] ?? '',
            ],
            [
                'title' => mb_substr($title, 0, 255),
                'actor' => $attributes['actor'] ?? null,
                'url' => $attributes['url'] ?? null,
                'meta' => $attributes['meta'] ?? null,
                'happened_at' => $attributes['happened_at'] ?? now(),
            ],
        );

        // `wasRecentlyCreated` is the only honest signal: a redelivery and a
        // sweep both rewrite rows they have already seen, and rewriting is not
        // news to tell anybody about.
        if ($row->wasRecentlyCreated) {
            $this->created[] = $row;
        }
    }

    private function firstLine(string $message): string
    {
        return trim(strtok($message, "\n") ?: $message);
    }

    private function time(?string $value): ?Carbon
    {
        return filled($value) ? Carbon::parse($value) : null;
    }
}
