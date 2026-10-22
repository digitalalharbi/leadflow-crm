<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A published release — the answer to "what version is live?". */
class RepositoryRelease extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'project_repository_id', 'release_id', 'tag_name', 'name',
        'draft', 'prerelease', 'commit_sha', 'author_login', 'html_url', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'release_id' => 'integer',
            'draft' => 'boolean',
            'prerelease' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(ProjectRepository::class, 'project_repository_id');
    }
}
