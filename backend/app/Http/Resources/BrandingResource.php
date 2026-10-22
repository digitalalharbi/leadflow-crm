<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything the SPA needs to render as the customer's own product.
 * Nothing in the React app hard-codes a product name or colour.
 */
class BrandingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tenant = $this->resource;
        $branding = $tenant->branding;
        $settings = $tenant->settings;

        return [
            'tenant_id' => $tenant->uuid,
            'slug' => $tenant->slug,
            'company_name' => $branding?->company_name ?? $tenant->name,
            // Null when the customer has not named the product: the interface then
            // shows مهتم. It used to fall back to the tenant's own name, which
            // renamed the product for every workspace nobody had customised.
            'system_name' => filled($branding?->system_name) ? $branding->system_name : null,
            'logo_url' => $branding?->logo_url,
            'login_logo_url' => $branding?->login_logo_url ?? $branding?->logo_url,
            'login_background_url' => $branding?->login_background_url,
            'favicon_url' => $branding?->favicon_url,
            'primary_color' => $branding?->primary_color ?? '#2563eb',
            'secondary_color' => $branding?->secondary_color ?? '#0f172a',
            'support_email' => $branding?->support_email,
            'support_phone' => $branding?->support_phone,
            'locale' => $settings?->locale ?? 'ar',
            'currency' => $settings?->currency ?? 'SAR',
            'timezone' => $settings?->timezone ?? 'Asia/Riyadh',
        ];
    }
}
