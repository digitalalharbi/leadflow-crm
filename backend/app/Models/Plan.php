<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One line of the catalogue the platform owner sells. Platform-level: it belongs to no company, so it carries no
 * tenant scope. Prices are nullable on purpose — "not priced yet" is a real state, and revenue built on it reads N/A.
 */
class Plan extends Model
{
    use HasUuid, SoftDeletes;

    /** Every feature a plan or an add-on can switch on. The backend refuses the routes of one that is off. */
    public const FEATURES = [
        'tracking_hub', 'advanced_forms', 'automations', 'attribution', 'exports',
        'api', 'advanced_reports', 'whatsapp', 'custom_branding', 'multiple_projects',
        // What a report can be sold with, on top of the library itself (advanced_reports).
        'report_pdf', 'report_excel', 'advanced_templates', 'advanced_attribution', 'white_label_reports', 'client_otp',
    ];

    /** The limits a plan sets. Null means unlimited. */
    public const LIMITS = ['projects', 'leads_per_month', 'storage_mb'];

    protected $fillable = [
        'key', 'name', 'description', 'price_monthly', 'price_yearly', 'extra_seat_price', 'currency',
        'included_seats', 'max_seats', 'limits', 'features', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'extra_seat_price' => 'decimal:2',
            'included_seats' => 'integer',
            'max_seats' => 'integer',
            'limits' => 'array',
            'features' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    public function isPriced(): bool
    {
        return $this->price_monthly !== null || $this->price_yearly !== null;
    }
}
