<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A file an export job is making or has made: who asked, what, and until when the link opens it. */
class DataExport extends Model
{
    use BelongsToTenant;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    /** What can be exported, and in which formats. */
    public const KINDS = [
        'leads' => ['csv', 'xlsx'],
        'company' => ['zip'],
    ];

    /** How long a finished file is kept. */
    public const KEEP_HOURS = 24;

    protected $fillable = ['user_id', 'kind', 'format', 'filters', 'status', 'path', 'filename', 'rows', 'bytes', 'error', 'finished_at', 'expires_at'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
            'rows' => 'integer',
            'bytes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $export) => $export->uuid ??= (string) Str::uuid());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::READY && $this->path !== null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
