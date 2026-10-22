<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Somebody outside the team who brings customers — and the links that prove it. */
class Partner extends Model
{
    use BelongsToTenant, HasUuid, SoftDeletes;

    public const TYPES = ['broker', 'influencer', 'affiliate', 'external_agent'];

    public const COMMISSION_TYPES = ['percentage', 'fixed'];

    protected $fillable = ['name', 'type', 'company', 'contact_name', 'email', 'phone', 'commission_type', 'commission_percent', 'commission_fixed_minor', 'show_revenue', 'is_active', 'notes', 'created_by'];

    public function payments(): HasMany
    {
        return $this->hasMany(PartnerCommissionPayment::class);
    }

    protected function casts(): array
    {
        return ['commission_percent' => 'decimal:2', 'commission_fixed_minor' => 'integer', 'show_revenue' => 'boolean', 'is_active' => 'boolean'];
    }
}
