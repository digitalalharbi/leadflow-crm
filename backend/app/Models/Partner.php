<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Somebody outside the team who brings customers — and the links that prove it. */
class Partner extends Model
{
    use BelongsToTenant, HasUuid, SoftDeletes;

    public const TYPES = ['broker', 'influencer', 'affiliate', 'external_agent'];

    protected $fillable = ['name', 'type', 'company', 'contact_name', 'email', 'phone', 'commission_percent', 'show_revenue', 'is_active', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['commission_percent' => 'decimal:2', 'show_revenue' => 'boolean', 'is_active' => 'boolean'];
    }
}
