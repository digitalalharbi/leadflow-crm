<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A report that sends itself: how often, to whom, and when it last did. */
class ReportSchedule extends Model
{
    use BelongsToTenant, HasUuid;

    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    protected $fillable = ['report_share_id', 'frequency', 'weekday', 'month_day', 'send_hour', 'recipients', 'message', 'enabled', 'next_run_at', 'last_run_at', 'last_status', 'created_by'];

    protected function casts(): array
    {
        return ['recipients' => 'array', 'enabled' => 'boolean', 'weekday' => 'integer', 'month_day' => 'integer', 'send_hour' => 'integer', 'next_run_at' => 'datetime', 'last_run_at' => 'datetime'];
    }

    public function share(): BelongsTo
    {
        return $this->belongsTo(ReportShare::class, 'report_share_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
