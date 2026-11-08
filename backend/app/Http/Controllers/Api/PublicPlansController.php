<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Support\ApiResponse;

/**
 * The marketing site's price list: the active plans, read live from the platform owner's catalogue. A plan that has no price
 * yet is shown as unpriced, so the page says "contact us" and never invents a number.
 *
 * It does NOT open companies. A trial is asked for through the contact form and the owner provisions the workspace
 * (RealUseReadinessTest pins that no public route creates a company).
 */
class PublicPlansController extends Controller
{
    public function plans()
    {
        $plans = Plan::query()->where('is_active', true)->orderBy('sort_order')->get()->map(fn (Plan $plan) => [
            'key' => $plan->key,
            'name' => $plan->name,
            'description' => $plan->description,
            'price_monthly' => $plan->price_monthly,
            'price_yearly' => $plan->price_yearly,
            'currency' => $plan->currency,
            'included_seats' => $plan->included_seats,
            'features' => $plan->features ?? [],
            'limits' => $plan->limits ?? [],
            // Not priced yet is a real state: the page says "contact us", it never invents a number.
            'priced' => $plan->isPriced(),
        ])->values();

        return ApiResponse::success(['plans' => $plans, 'trial_days' => (int) config('tenancy.defaults.trial_days')]);
    }
}
