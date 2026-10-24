<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reference to something billed to a company: issued by hand from the console, or mirrored from a payment provider
 * once one is wired. A company's admin reads its own; only the platform writes them.
 */
class Invoice extends Model
{
    use BelongsToTenant, HasUuid;

    public const STATUSES = ['draft', 'issued', 'paid', 'void'];

    protected $fillable = [
        'tenant_id', 'plan_id', 'number', 'amount', 'currency', 'period_start', 'period_end', 'status',
        'provider', 'provider_ref', 'issued_at', 'paid_at', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'period_start' => 'date',
            'period_end' => 'date',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withTrashed();
    }
}
