<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Domain\Access\Permissions;
use App\Domain\Access\WorkspaceModules;
use App\Domain\Billing\BillingProvider;
use App\Domain\Billing\Entitlements;
use App\Domain\Billing\SubscriptionSummary;
use App\Domain\Platform\PlatformAudit;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Tenant;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A company's subscription, run by the platform owner: the plan, seats, add-ons and limits it has, and the lifecycle
 * moves — activate, extend, mark past due, suspend, reactivate, cancel. Each move is one audited row; suspending or
 * cancelling never deletes anything.
 */
class SubscriptionController extends Controller
{
    public function __construct(
        private TenantContext $context,
        private PlatformAudit $audit,
        private BillingProvider $billing,
    ) {}

    public function show(string $uuid)
    {
        $tenant = $this->tenant($uuid);

        return ApiResponse::success($this->payload($tenant));
    }

    public function update(Request $request, string $uuid)
    {
        $tenant = $this->tenant($uuid);

        $data = $request->validate([
            'plan_id' => ['sometimes', 'uuid'],
            'billing_cycle' => ['sometimes', Rule::in(['monthly', 'yearly'])],
            'extra_seats' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'seat_limit_override' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'limit_overrides' => ['sometimes', 'nullable', 'array'],
            'limit_overrides.*' => ['nullable', 'integer', 'min:0'],
            'disabled_modules' => ['sometimes', 'nullable', 'array'],
            'disabled_modules.*' => ['string', Rule::in(array_keys(WorkspaceModules::MODULES))],
            'addons' => ['sometimes', 'nullable', 'array'],
            'addons.*' => ['string', Rule::in(Plan::FEATURES)],
            'trial_ends_at' => ['sometimes', 'nullable', 'date'],
            'subscription_ends_at' => ['sometimes', 'nullable', 'date'],
            'billing_customer_ref' => ['sometimes', 'nullable', 'string', 'max:255'],
            'billing_subscription_ref' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if (isset($data['plan_id'])) {
            $plan = Plan::where('uuid', $data['plan_id'])->firstOrFail();
            $data['plan_id'] = $plan->id;
            $data['plan'] = $plan->key;
        }

        if (array_key_exists('limit_overrides', $data) && $data['limit_overrides'] !== null) {
            $data['limit_overrides'] = array_intersect_key($data['limit_overrides'], array_flip(Plan::LIMITS)) ?: null;
        }

        if (array_key_exists('addons', $data)) {
            $data['addons'] = array_values(array_unique($data['addons'] ?? [])) ?: null;
        }

        $before = $tenant->only(array_keys($data));
        $tenant->forceFill($data)->save();

        $this->audit->log('subscription.updated', $request->user(), $tenant, $tenant, ['before' => $before, 'after' => $tenant->only(array_keys($data))]);

        return ApiResponse::success($this->payload($tenant->fresh()));
    }

    /**
     * One lifecycle move. `activate` puts the company on a paid plan (manual activation when no gateway takes payment);
     * `extend` pushes the trial or paid period out; `past_due` keeps it working with a warning; `suspend` and `cancel`
     * close the workspace and keep its data; `reactivate` opens it again.
     */
    public function transition(Request $request, string $uuid, string $action)
    {
        $tenant = $this->tenant($uuid);
        $from = $tenant->subscription_status;

        $data = $request->validate([
            'plan_id' => [$action === 'activate' ? 'required' : 'sometimes', 'uuid'],
            'billing_cycle' => ['sometimes', Rule::in(['monthly', 'yearly'])],
            'until' => ['sometimes', 'nullable', 'date', 'after:now'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        switch ($action) {
            case 'activate':
                $plan = Plan::where('uuid', $data['plan_id'])->where('is_active', true)->firstOrFail();
                $cycle = $data['billing_cycle'] ?? $tenant->billing_cycle ?? 'monthly';
                $until = isset($data['until']) ? now()->parse($data['until']) : ($cycle === 'yearly' ? now()->addYear() : now()->addMonth());
                $this->billing->activate($tenant, $plan, $cycle, $until);
                break;

            case 'extend':
                if (! isset($data['days']) && ! isset($data['until'])) {
                    throw ValidationException::withMessages(['days' => ['Say how long to extend by, or until when.']]);
                }

                $column = $tenant->subscription_status === 'trialing' ? 'trial_ends_at' : 'subscription_ends_at';
                $base = $tenant->{$column} && $tenant->{$column}->isFuture() ? $tenant->{$column}->copy() : now();
                $tenant->forceFill([
                    $column => isset($data['until']) ? now()->parse($data['until']) : $base->addDays((int) $data['days']),
                    // Extending a paid period settles a late payment.
                    'subscription_status' => $tenant->subscription_status === 'past_due' ? 'active' : $tenant->subscription_status,
                ])->save();
                break;

            case 'past_due':
                $this->guard(in_array($from, ['active'], true), 'Only an active subscription can fall past due.');
                $tenant->forceFill(['subscription_status' => 'past_due'])->save();
                break;

            case 'suspend':
                $this->guard(! in_array($from, ['suspended', 'cancelled'], true), 'This subscription is already closed.');
                $tenant->forceFill(['subscription_status' => 'suspended', 'suspended_at' => now(), 'status_reason' => $data['reason'] ?? null])->save();
                break;

            case 'reactivate':
                $this->guard(in_array($from, ['suspended', 'cancelled', 'past_due'], true), 'Only a suspended, cancelled or past-due subscription is reactivated.');
                $tenant->forceFill([
                    'subscription_status' => 'active',
                    'suspended_at' => null, 'cancelled_at' => null, 'status_reason' => null,
                    'subscription_ends_at' => isset($data['until']) ? now()->parse($data['until'])
                        : ($tenant->subscription_ends_at?->isFuture() ? $tenant->subscription_ends_at : now()->addMonth()),
                ])->save();
                break;

            case 'cancel':
                $this->guard($from !== 'cancelled', 'This subscription is already cancelled.');
                $this->billing->cancel($tenant, $data['reason'] ?? null);
                break;

            default:
                abort(404);
        }

        $this->audit->log('subscription.'.$action, $request->user(), $tenant, $tenant, [
            'from' => $from, 'to' => $tenant->subscription_status,
            'plan' => $tenant->plan, 'until' => ($tenant->subscription_status === 'trialing' ? $tenant->trial_ends_at : $tenant->subscription_ends_at)?->toIso8601String(),
            'reason' => $data['reason'] ?? null,
        ]);

        return ApiResponse::success($this->payload($tenant->fresh()));
    }

    private function guard(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['status' => [$message]]);
        }
    }

    private function tenant(string $uuid): Tenant
    {
        return Tenant::where('uuid', $uuid)->firstOrFail();
    }

    /** Who can reach what in this company, as the API would decide it: the same chain, read out. */
    public function accessMatrix(string $uuid)
    {
        $tenant = $this->tenant($uuid);
        $off = WorkspaceModules::disabled($tenant);
        $roles = array_values(array_filter(Role::cases(), fn (Role $role) => $role !== Role::SuperAdmin));

        return ApiResponse::success([
            'roles' => array_map(fn (Role $role) => $role->value, $roles),
            'rows' => collect(WorkspaceModules::MODULES)->map(fn (array $module, string $key) => [
                'module' => $key,
                'enabled' => ! in_array($key, $off, true),
                'roles' => collect($roles)->mapWithKeys(fn (Role $role) => [$role->value => ! in_array($key, $off, true) && in_array($module['permission'], Permissions::forRole($role), true)])->all(),
            ])->values(),
        ]);
    }

    private function payload(Tenant $tenant): array
    {
        return $this->context->withoutScope(fn () => [
            'subscription' => SubscriptionSummary::for($tenant, withUsage: true) + [
                'plan_id' => $tenant->subscriptionPlan?->uuid,
                'billing_cycle' => $tenant->billing_cycle,
                'extra_seats' => $tenant->extra_seats,
                'seat_limit_override' => $tenant->seat_limit_override,
                'limit_overrides' => $tenant->limit_overrides,
                'addons' => $tenant->addons ?? [],
                'disabled_modules' => WorkspaceModules::disabled($tenant),
                'modules' => array_keys(WorkspaceModules::MODULES),
                'billing_provider' => $tenant->billing_provider,
                'billing_customer_ref' => $tenant->billing_customer_ref,
                'billing_subscription_ref' => $tenant->billing_subscription_ref,
                'suspended_at' => $tenant->suspended_at?->toIso8601String(),
                'cancelled_at' => $tenant->cancelled_at?->toIso8601String(),
                'status_reason' => $tenant->status_reason,
                'monthly_value' => self::monthlyValue($tenant),
            ],
            'invoices' => Invoice::withoutGlobalScopes()->where('tenant_id', $tenant->id)->latest('id')->limit(50)->get()
                ->map(fn (Invoice $i) => InvoiceController::present($i)),
        ]);
    }

    /**
     * What this company is worth a month at the configured prices: null when its plan has no price, so a sum built on
     * it can say "not configured" instead of inventing a zero.
     *
     * @return array{amount: float, currency: string}|null
     */
    public static function monthlyValue(Tenant $tenant): ?array
    {
        $plan = Entitlements::for($tenant)->plan();

        if ($plan === null || ! $plan->isPriced()) {
            return null;
        }

        $base = $tenant->billing_cycle === 'yearly' && $plan->price_yearly !== null
            ? (float) $plan->price_yearly / 12
            : (float) ($plan->price_monthly ?? ((float) $plan->price_yearly / 12));

        $seats = (float) ($plan->extra_seat_price ?? 0) * (int) $tenant->extra_seats;

        return ['amount' => round($base + $seats, 2), 'currency' => $plan->currency];
    }
}
