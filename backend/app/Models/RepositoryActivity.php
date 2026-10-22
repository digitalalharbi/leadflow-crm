<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of the development timeline. */
class RepositoryActivity extends Model
{
    use BelongsToTenant, HasUuid;

    protected $table = 'repository_activity';

    protected $fillable = [
        'project_repository_id', 'kind', 'title', 'actor',
        'ref', 'commit_sha', 'url', 'meta', 'happened_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'happened_at' => 'datetime',
        ];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(ProjectRepository::class, 'project_repository_id');
    }
}
