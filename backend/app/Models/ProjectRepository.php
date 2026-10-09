<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The link between a project in مهتم and the repository it is built in.
 *
 * Deliberately thin. It knows which repository, which branches mean what, and
 * how far the last sweep got; everything about *what happened* in that
 * repository is recorded elsewhere, so that re-mapping a project does not throw
 * away its development history.
 */
class ProjectRepository extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'project_id', 'integration_id', 'provider',
        'repository_id', 'repository_full_name',
        'default_branch', 'develop_branch', 'production_branch',
        'sync_cursor', 'last_sync_at', 'last_webhook_at', 'discovery', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'repository_id' => 'integer',
            'discovery' => 'array',
            'sync_cursor' => 'datetime',
            'last_sync_at' => 'datetime',
            'last_webhook_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The connection whose credentials reach this repository. */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Integration::class, 'integration_id');
    }

    /**
     * Which of this repository's branches مهتم treats as which environment.
     *
     * Null means "not mapped", which is different from "the default branch":
     * a repository with no `develop` has no staging, and calling its production
     * branch staging would label every release wrongly.
     */
    public function environmentFor(?string $branch): ?string
    {
        if (blank($branch)) {
            return null;
        }

        return match ($branch) {
            $this->production_branch => 'production',
            $this->develop_branch => 'staging',
            default => null,
        };
    }

    public function webUrl(): string
    {
        return 'https://github.com/'.$this->repository_full_name;
    }
}
