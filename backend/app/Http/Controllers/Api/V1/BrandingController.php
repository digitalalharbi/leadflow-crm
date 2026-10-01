<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BrandingResource;
use App\Models\Tenant;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * Public, unauthenticated: the login screen must render the customer's logo and
 * colours before anyone has signed in. Returns only presentational fields.
 */
class BrandingController extends Controller
{
    public function show(Request $request)
    {
        $slug = $request->query('tenant') ?: $this->slugFromHost($request->getHost());

        if (! $slug) {
            return ApiResponse::success(null);
        }

        $tenant = Tenant::with('branding', 'settings')
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (! $tenant) {
            return ApiResponse::success(null);
        }

        return ApiResponse::success(new BrandingResource($tenant));
    }

    /** client1.example.com => "client1" */
    private function slugFromHost(string $host): ?string
    {
        $central = config('tenancy.central_domain');

        if ($central && str_ends_with($host, '.'.$central)) {
            $slug = substr($host, 0, -(strlen($central) + 1));

            return in_array($slug, config('tenancy.reserved_slugs'), true) ? null : $slug;
        }

        return null;
    }
}
