<?php

namespace App\Models;

use App\Models\Concerns\TenantOwnedSingleton;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantBranding extends Model
{
    use HasFactory, TenantOwnedSingleton;

    protected $fillable = [
        'company_name', 'system_name', 'logo_url', 'login_logo_url',
        'login_background_url', 'favicon_url', 'primary_color',
        'secondary_color', 'support_email', 'support_phone',
    ];
}
