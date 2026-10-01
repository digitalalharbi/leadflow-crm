<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookLog extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    /** Platform-level rows (super admin actions / unknown endpoints) have no tenant. */
    public bool $tenantOptional = true;

    protected $fillable = [
        'tenant_id', 'webhook_endpoint_id', 'lead_id', 'provider', 'status',
        'http_status', 'ip', 'payload', 'error', 'received_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'received_at' => 'datetime'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
