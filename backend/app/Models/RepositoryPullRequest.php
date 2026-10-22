<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A pull request, as far as GitHub has told us. */
class RepositoryPullRequest extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'project_repository_id', 'number', 'title', 'author_login',
        'head_branch', 'base_branch', 'state', 'draft',
        'checks_state', 'review_state', 'mergeable_state',
        'html_url', 'opened_at', 'updated_at_github', 'merged_at', 'merged_by',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'draft' => 'boolean',
            'opened_at' => 'datetime',
            'updated_at_github' => 'datetime',
            'merged_at' => 'datetime',
        ];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(ProjectRepository::class, 'project_repository_id');
    }

    /**
     * What a manager scanning the list actually wants to know, in one word.
     *
     * Order matters: a draft is not "ready" however green it is, and a failing
     * build outranks an approval — approving a branch that does not build is a
     * review of something that cannot ship.
     */
    public function readiness(): string
    {
        return match (true) {
            $this->state === 'merged' => 'merged',
            $this->state === 'closed' => 'closed',
            $this->draft => 'draft',
            $this->checks_state === 'failed' => 'ci_failed',
            $this->checks_state === 'pending' => 'waiting_on_ci',
            $this->review_state === 'changes_requested' => 'changes_requested',
            $this->review_state === 'approved' && $this->checks_state === 'passed' => 'ready_to_merge',
            default => 'open',
        };
    }
}
