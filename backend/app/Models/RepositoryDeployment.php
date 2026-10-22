<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something that was deployed, and whether anybody checked.
 *
 * Four different facts live here, and the product must never collapse them:
 * the code passed, a deployment was asked for, the deployer reported success,
 * and the application answered over HTTP. Only the last one is "منشور".
 */
class RepositoryDeployment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'project_repository_id', 'deployment_id', 'environment', 'ref', 'commit_sha',
        'state', 'description', 'html_url', 'environment_url',
        'verification_status', 'verified_at', 'created_at_github', 'updated_at_github',
    ];

    protected function casts(): array
    {
        return [
            'deployment_id' => 'integer',
            'verified_at' => 'datetime',
            'created_at_github' => 'datetime',
            'updated_at_github' => 'datetime',
        ];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(ProjectRepository::class, 'project_repository_id');
    }

    /** GitHub says the deployer finished without error. Nobody has asked the app. */
    public function reportedLive(): bool
    {
        return $this->state === 'success';
    }

    /** Somebody got an answer out of it. This is the only one worth the word. */
    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    /**
     * What the screen says in one word, with "reported" never shown as "live".
     *
     * A deployment the deployer called a success and nobody verified is exactly
     * the state the last pipeline displayed as deployed while nothing had
     * shipped, so it gets a word of its own.
     */
    public function truth(): string
    {
        return match (true) {
            in_array($this->state, ['failure', 'error'], true) => 'failed',
            $this->state === 'inactive' => 'superseded',
            $this->isVerified() => 'verified',
            $this->reportedLive() => 'reported',
            default => 'in_progress',
        };
    }
}
