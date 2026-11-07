<?php

namespace App\Http\Controllers\Api;

use App\Domain\Mail\EmailDeliveries;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The marketing site's own sales funnel: the plans on offer, and a company opening its own trial workspace.
 *
 * Nobody gets a password here. The owner is emailed a setup link and chooses their own, so a workspace is only ever
 * usable by whoever owns the inbox. The answer is the same whether the address was new or known, so the form is no way
 * to learn who already has an account. It can be switched off with MOHTAM_SELF_SIGNUP_ENABLED=false.
 */
class PublicSignupController extends Controller
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

        return ApiResponse::success(['plans' => $plans, 'signup' => $this->enabled(), 'trial_days' => (int) config('tenancy.defaults.trial_days')]);
    }

    public function store(Request $request, TenantProvisioningService $provisioning)
    {
        abort_unless($this->enabled(), 404);

        $data = $request->validate([
            'company' => ['required', 'string', 'min:2', 'max:120'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'plan' => ['nullable', 'string', 'max:64'],
            'consent' => ['accepted'],
            // A field no person sees. Filled in, it is a bot, and it is told all went well.
            'website' => ['nullable', 'string', 'max:0'],
        ]);

        $email = strtolower($data['email']);
        $answer = ApiResponse::success(null, 'If the address can receive mail, a link to open the workspace is on its way.');

        if (EmailDeliveries::isReserved($email)) {
            return $answer;
        }

        // Three a day per address: nobody's inbox is a target, and a typo gets a second try.
        if (! RateLimiter::attempt('signup:'.hash('sha256', $email), 3, fn () => true, 86400)) {
            return $answer;
        }

        if (User::withoutGlobalScopes()->where('email', $email)->exists()) {
            Log::info('signup.refused', ['reason' => 'address_in_use']);

            return $answer;
        }

        $plan = Plan::query()->where('is_active', true)->where('key', $data['plan'] ?? '')->value('key');

        $result = $provisioning->provision([
            'company_name' => trim($data['company']),
            'subdomain' => $provisioning->uniqueSlug($data['company']),
            'admin_name' => trim($data['name']),
            'admin_email' => $email,
            'plan' => $plan ?? config('tenancy.defaults.plan'),
            'assignment_mode' => 'round_robin',
        ]);

        /** @var Tenant $tenant */
        $tenant = $result['tenant'];
        Log::info('signup.workspace_created', ['slug' => $tenant->slug, 'owner_hash' => hash('sha256', $email)]);

        return $answer;
    }

    private function enabled(): bool
    {
        return (bool) config('tenancy.self_signup', true);
    }
}
