<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Domain\Platform\PlatformAudit;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The catalogue, managed by the platform owner. Nothing about a plan is in code: its name, price, seats, limits and
 * features are all edited here, and a plan in use is retired, never deleted.
 */
class PlanController extends Controller
{
    public function __construct(private PlatformAudit $audit) {}

    public function index()
    {
        $plans = Plan::withCount('tenants')->orderBy('sort_order')->orderBy('id')->get();

        return ApiResponse::success([
            'plans' => $plans->map(fn (Plan $p) => self::present($p)),
            'features' => Plan::FEATURES,
            'limits' => Plan::LIMITS,
        ]);
    }

    public function store(Request $request)
    {
        $plan = Plan::create($this->validated($request));
        $this->audit->log('plan.created', $request->user(), null, $plan, ['key' => $plan->key]);

        return ApiResponse::created(['plan' => self::present($plan->loadCount('tenants'))]);
    }

    public function update(Request $request, string $uuid)
    {
        $plan = Plan::where('uuid', $uuid)->firstOrFail();
        $data = $this->validated($request, $plan);
        $before = $plan->only(array_keys($data));
        $plan->update($data);

        $this->audit->log('plan.updated', $request->user(), null, $plan, ['before' => $before, 'after' => $plan->only(array_keys($data))]);

        return ApiResponse::success(['plan' => self::present($plan->fresh()->loadCount('tenants'))]);
    }

    private function validated(Request $request, ?Plan $plan = null): array
    {
        $sometimes = $plan ? 'sometimes' : 'required';

        $data = $request->validate([
            'key' => [$sometimes, 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/', Rule::unique('plans', 'key')->ignore($plan?->id)],
            'name' => [$sometimes, 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'price_monthly' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999'],
            'price_yearly' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'extra_seat_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'included_seats' => [$sometimes, 'integer', 'min:1', 'max:100000'],
            'max_seats' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'limits' => ['sometimes', 'array'],
            'limits.*' => ['nullable', 'integer', 'min:0'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['string', Rule::in(Plan::FEATURES)],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        if (isset($data['limits'])) {
            $data['limits'] = collect(Plan::LIMITS)->mapWithKeys(fn ($k) => [$k => array_key_exists($k, $data['limits']) ? $data['limits'][$k] : ($plan?->limits[$k] ?? null)])->all();
        } elseif (! $plan) {
            $data['limits'] = array_fill_keys(Plan::LIMITS, null);
        }

        if (isset($data['features'])) {
            $data['features'] = array_values(array_unique($data['features']));
        } elseif (! $plan) {
            $data['features'] = [];
        }

        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }

        return $data;
    }

    public static function present(Plan $plan): array
    {
        return [
            'id' => $plan->uuid,
            'key' => $plan->key,
            'name' => $plan->name,
            'description' => $plan->description,
            'price_monthly' => $plan->price_monthly,
            'price_yearly' => $plan->price_yearly,
            'extra_seat_price' => $plan->extra_seat_price,
            'currency' => $plan->currency,
            'included_seats' => $plan->included_seats,
            'max_seats' => $plan->max_seats,
            'limits' => $plan->limits,
            'features' => $plan->features,
            'is_active' => $plan->is_active,
            'is_priced' => $plan->isPriced(),
            'sort_order' => $plan->sort_order,
            'tenants_count' => $plan->tenants_count ?? null,
        ];
    }
}
