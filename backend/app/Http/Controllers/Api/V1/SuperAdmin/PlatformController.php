<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Domain\Billing\UsageMeter;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The platform owner's business view and trail. Every figure is counted from rows; money is either computed from
 * prices the owner configured or summed from invoices they recorded — and says "not configured" when neither exists.
 */
class PlatformController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function dashboard()
    {
        return $this->context->withoutScope(function () {
            $tenants = Tenant::with('subscriptionPlan')->get();
            $byStatus = $tenants->groupBy(fn (Tenant $t) => $t->lifecycleState())->map->count();

            // Recurring revenue: companies paying now (active or in their past-due grace), at the configured prices.
            $paying = $tenants->filter(fn (Tenant $t) => in_array($t->subscription_status, ['active', 'past_due'], true) && $t->isSubscriptionUsable());
            $mrr = [];
            $unpriced = 0;

            foreach ($paying as $tenant) {
                $value = SubscriptionController::monthlyValue($tenant);

                if ($value === null) {
                    $unpriced++;

                    continue;
                }

                $mrr[$value['currency']] = round(($mrr[$value['currency']] ?? 0) + $value['amount'], 2);
            }

            $paid = Invoice::withoutGlobalScopes()->where('status', 'paid')->where('paid_at', '>=', now()->subDays(30))
                ->select('currency', DB::raw('sum(amount) as total'))->groupBy('currency')->pluck('total', 'currency')
                ->map(fn ($v) => round((float) $v, 2));

            $alerts = [];

            foreach ($tenants->where('is_active', true) as $tenant) {
                foreach (UsageMeter::for($tenant)->summary() as $meter => $row) {
                    if ($row['alert']) {
                        $alerts[] = ['tenant' => $tenant->uuid, 'name' => $tenant->name, 'meter' => $meter] + $row;
                    }
                }
            }

            return ApiResponse::success([
                'companies' => [
                    'total' => $tenants->count(),
                    'trialing' => (int) ($byStatus['trialing'] ?? 0),
                    'active' => (int) ($byStatus['active'] ?? 0),
                    'past_due' => (int) ($byStatus['past_due'] ?? 0),
                    'suspended' => (int) ($byStatus['suspended'] ?? 0),
                    'cancelled' => (int) ($byStatus['cancelled'] ?? 0),
                    'expired' => (int) (($byStatus['trial_expired'] ?? 0) + ($byStatus['expired'] ?? 0)),
                    'disabled' => (int) ($byStatus['disabled'] ?? 0),
                    'new_last_30_days' => $tenants->where('created_at', '>=', now()->subDays(30))->count(),
                    'trials_ending_7_days' => $tenants->filter(fn (Tenant $t) => $t->subscription_status === 'trialing'
                        && $t->trial_ends_at && $t->trial_ends_at->between(now(), now()->addDays(7)))->count(),
                ],
                'seats' => [
                    'active' => User::whereNotNull('tenant_id')->where('is_active', true)
                        ->whereNotIn('role', config('billing.seatless_roles'))->count(),
                ],
                'usage' => [
                    'leads_last_30_days' => Lead::where('created_at', '>=', now()->subDays(30))->count(),
                    'leads_total' => Lead::count(),
                ],
                'plans' => Plan::withCount('tenants')->orderBy('sort_order')->get()
                    ->map(fn (Plan $p) => ['key' => $p->key, 'name' => $p->name, 'count' => $p->tenants_count, 'is_priced' => $p->isPriced()]),
                'revenue' => [
                    // null — not zero — when no paying company is on a priced plan.
                    'mrr' => $mrr === [] ? null : $mrr,
                    'mrr_source' => 'configured_prices',
                    'paying_companies' => $paying->count(),
                    'paying_unpriced' => $unpriced,
                    'invoices_paid_30_days' => $paid->isEmpty() ? null : $paid,
                ],
                'usage_alerts' => $alerts,
            ]);
        });
    }

    public function audit(Request $request)
    {
        $rows = PlatformAuditLog::with(['actor:id,name,email', 'tenant:id,uuid,name'])
            ->when($request->filled('tenant'), fn ($q) => $q->whereHas('tenant', fn ($t) => $t->where('uuid', $request->input('tenant'))))
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', $request->input('action').'%'))
            ->latest('id')
            ->paginate(min((int) $request->input('per_page', 50), 100));

        return response()->json([
            'success' => true,
            'data' => collect($rows->items())->map(fn (PlatformAuditLog $r) => [
                'id' => $r->id,
                'action' => $r->action,
                'actor' => $r->actor ? ['name' => $r->actor->name, 'email' => $r->actor->email] : null,
                'tenant' => $r->tenant ? ['id' => $r->tenant->uuid, 'name' => $r->tenant->name] : null,
                'subject_type' => $r->subject_type,
                'changes' => $r->changes,
                'ip' => $r->ip,
                'created_at' => $r->created_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
            'message' => null,
            'errors' => null,
        ]);
    }
}
