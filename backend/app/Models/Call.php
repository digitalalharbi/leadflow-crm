<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to reach somebody by phone.
 *
 * `manual` is the honest default: the rep dialled from their own phone, so the
 * only facts مهتم has are that they pressed the button and what they said
 * afterwards. Duration stays null rather than being estimated, because a number
 * on a screen is read as measured.
 */
class Call extends Model
{
    use BelongsToTenant, HasUuid;

    public const INITIATED = 'initiated';

    public const RINGING = 'ringing';

    public const ANSWERED = 'answered';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const NO_ANSWER = 'no_answer';

    protected $fillable = [
        'lead_id', 'user_id', 'provider', 'external_id', 'direction',
        'status', 'duration_seconds', 'outcome', 'started_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whether anything but a person told us how this ended. */
    public function wasMeasured(): bool
    {
        return $this->provider !== 'manual' && $this->duration_seconds !== null;
    }
}
