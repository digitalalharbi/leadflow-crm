<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Import extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    public const PENDING = 'pending';

    public const MAPPING = 'mapping';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected $fillable = [
        'user_id', 'original_filename', 'path', 'status', 'headers', 'mapping',
        'defaults', 'total_rows', 'imported_rows', 'duplicate_rows', 'failed_rows',
        'error', 'completed_at',
    ];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'mapping' => 'array',
            'defaults' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
