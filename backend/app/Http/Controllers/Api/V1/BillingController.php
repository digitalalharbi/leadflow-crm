<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\SubscriptionSummary;
use App\Http\Controllers\Api\V1\SuperAdmin\InvoiceController;
use App\Http\Controllers\Api\V1\SuperAdmin\PlanController;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Plan;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * A company's own subscription page: its plan, usage against limits, its invoices, and the plans it could move to.
 * Read-only — changing the plan is the platform's move (or the payment provider's, once one is wired).
 * Deliberately outside `tenant.active`: a suspended company must still be able to see why.
 */
class BillingController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isAdmin(), 403, 'Only the workspace admin sees billing.');
        $tenant = app(TenantContext::class)->get();

        return ApiResponse::success([
            'subscription' => SubscriptionSummary::for($tenant, withUsage: true) + [
                'billing_cycle' => $tenant->billing_cycle,
                'extra_seats' => $tenant->extra_seats,
                'addons' => $tenant->addons ?? [],
                'billing_provider' => $tenant->billing_provider,
            ],
            // Invoices are tenant-scoped: the scope is bound to this company by the request.
            'invoices' => Invoice::latest('id')->limit(50)->get()->map(fn (Invoice $i) => InvoiceController::present($i)),
            'plans' => Plan::where('is_active', true)->orderBy('sort_order')->get()
                ->map(fn (Plan $p) => array_intersect_key(PlanController::present($p), array_flip([
                    'id', 'key', 'name', 'description', 'price_monthly', 'price_yearly', 'extra_seat_price', 'currency',
                    'included_seats', 'max_seats', 'limits', 'features', 'is_priced',
                ]))),
        ]);
    }
}
