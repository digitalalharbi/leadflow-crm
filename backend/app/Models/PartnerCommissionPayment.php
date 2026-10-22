<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One payment of commission to a partner. The balance is what is due less what these add up to. */
class PartnerCommissionPayment extends Model
{
    use BelongsToTenant, HasUuid;

    protected $fillable = ['partner_id', 'amount_minor', 'paid_on', 'reference', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'paid_on' => 'date'];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class)->withTrashed();
    }
}
