<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One run of one workflow. */
class RepositoryWorkflowRun extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'project_repository_id', 'run_id', 'name', 'event', 'branch', 'commit_sha',
        'status', 'conclusion', 'environment', 'html_url', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'run_id' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(ProjectRepository::class, 'project_repository_id');
    }

    /**
     * A single word for a run, with `skipped` kept separate from `passed`.
     *
     * This is the distinction the previous deployment pipeline got wrong: the
     * deploy job was written to skip rather than fail when the server was not
     * configured, and a skipped job reports success. Every run looked green and
     * nothing had ever been deployed. A skip is not a pass here, and the
     * deployment state below is not read from a workflow at all.
     */
    public function outcome(): string
    {
        if ($this->status !== 'completed') {
            return $this->status === 'in_progress' ? 'running' : 'queued';
        }

        return match ($this->conclusion) {
            'success' => 'passed',
            'failure', 'timed_out' => 'failed',
            'cancelled' => 'cancelled',
            'skipped' => 'skipped',
            default => 'unknown',
        };
    }
}
