<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One try at sending an email — what the provider said. The failure log is the ones that were not ok. */
class EmailDeliveryAttempt extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'email_delivery_id', 'attempt', 'provider', 'ok', 'retryable', 'error', 'provider_message_id', 'attempted_at'];

    protected function casts(): array
    {
        return ['ok' => 'boolean', 'retryable' => 'boolean', 'attempted_at' => 'datetime'];
    }
}
