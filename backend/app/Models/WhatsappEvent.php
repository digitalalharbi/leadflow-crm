<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * The webhook ledger: what we have already processed, and what arrived that we
 * could not place. Written on every delivery, read to decide whether a delivery
 * is a repeat.
 */
class WhatsappEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    /**
     * A delivery we refuse before identifying the tenant — a bad signature, an
     * unknown phone number id — still gets a row. That is the only record that
     * someone is probing the endpoint.
     */
    public bool $tenantOptional = true;

    public const TYPE_MESSAGE = 'message';

    public const TYPE_STATUS = 'status';

    public const TYPE_UNMATCHED = 'unmatched';

    public const TYPE_REJECTED = 'rejected';

    protected $fillable = [
        'tenant_id', 'integration_id', 'event_id', 'event_type',
        'phone_number_id', 'from_phone', 'outcome', 'detail', 'payload',
    ];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }

    /** Append-only: a row describes a delivery that already happened. */
    public function setUpdatedAt($value)
    {
        return $this;
    }
}
