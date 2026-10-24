<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One file, sent to one lead, and whether they opened it.
 *
 * The open is the point. A brochure sent is an activity; a brochure opened
 * twice is a buying signal, and it is the difference between a rep calling the
 * person who is reading about the project and the person who is not.
 */
class FileShare extends Model
{
    use BelongsToTenant, HasUuid;

    protected $fillable = [
        'project_file_id', 'lead_id', 'sent_by', 'channel', 'token',
        'opens', 'first_opened_at', 'last_opened_at',
    ];

    /** The token is the link. Returning it in a list would be handing it out. */
    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'opens' => 'integer',
            'first_opened_at' => 'datetime',
            'last_opened_at' => 'datetime',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(ProjectFile::class, 'project_file_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function wasOpened(): bool
    {
        return $this->opens > 0;
    }

    public function url(): string
    {
        return ShortLink::urlFor('share', (string) $this->token);
    }
}
