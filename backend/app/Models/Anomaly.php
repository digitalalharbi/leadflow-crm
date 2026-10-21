<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** A number that moved outside its normal range, found by the nightly pass. */
class Anomaly extends Model
{
    use BelongsToTenant, HasUuid;

    protected $fillable = ['kind', 'subject', 'severity', 'detected_on', 'observed', 'expected', 'context', 'alerted_at', 'dismissed_at'];

    protected function casts(): array
    {
        return ['detected_on' => 'date', 'observed' => 'float', 'expected' => 'float', 'context' => 'array', 'alerted_at' => 'datetime', 'dismissed_at' => 'datetime'];
    }
}
