<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The SaaS owner's console. Creating a client here is the entire onboarding
 * story — no code change, no deploy.
 */
class TenantController extends Controller
{
    public function __construct(
        private TenantProvisioningService $provisioning,
        private TenantContext $context,
        private AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        // Super admin reads deliberately bypass the tenant scope.
        return $this->context->withoutScope(function () use ($request) {
            $tenants = Tenant::query()
                ->when($request->filled('search'), fn ($q) => $q->where(fn ($s) => $s
                    ->where('name', 'ilike', '%'.$request->input('search').'%')
                    ->orWhere('slug', 'ilike', '%'.$request->input('search').'%')))
                ->when($request->filled('status'), fn ($q) => $q->where('subscription_status', $request->input('status')))
                ->with('branding')
                ->withCount(['users', 'leads'])
                ->orderByDesc('created_at')
                ->paginate(min((int) $request->input('per_page', 25), 100));

            return response()->json([
                'success' => true,
                'data' => collect($tenants->items())->map(fn ($t) => $this->present($t)),
                'meta' => [
                    'current_page' => $tenants->currentPage(),
                    'last_page' => $tenants->lastPage(),
                    'per_page' => $tenants->perPage(),
                    'total' => $tenants->total(),
                ],
                'message' => null,
                'errors' => null,
            ]);
        });
    }

    /** Platform-wide counters for the super admin dashboard. */
    public function stats()
    {
        return $this->context->withoutScope(function () {
            return ApiResponse::success([
                'tenants' => Tenant::count(),
                'active_tenants' => Tenant::where('is_active', true)->count(),
                'trialing' => Tenant::where('subscription_status', 'trialing')->count(),
                'users' => User::whereNotNull('tenant_id')->count(),
                'leads' => Lead::count(),
                'leads_last_30_days' => Lead::where('created_at', '>=', now()->subDays(30))->count(),
                'by_plan' => Tenant::select('plan', DB::raw('count(*) as aggregate'))
                    ->groupBy('plan')->get()
                    ->map(fn ($r) => ['key' => $r->plan, 'count' => (int) $r->aggregate]),
            ]);
        });
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'system_name' => ['nullable', 'string', 'max:255'],
            'subdomain' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/',
                Rule::notIn(config('tenancy.reserved_slugs'))],
            'logo_url' => ['nullable', 'url', 'max:2048'],
            'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:8'],
            'plan' => ['nullable', 'string', 'max:40'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'locale' => ['nullable', 'in:ar,en'],
            'currency' => ['nullable', 'string', 'size:3'],
            'timezone' => ['nullable', 'timezone'],
            'assignment_mode' => ['nullable', 'in:manual,round_robin'],
        ]);

        $result = $this->provisioning->provision($data);

        $this->audit->log('tenant.created', $request->user(), $result['tenant'], [
            'slug' => $result['tenant']->slug,
        ]);

        return ApiResponse::created([
            'tenant' => $this->present($result['tenant']->loadCount(['users', 'leads'])),
            'admin' => ['name' => $result['admin']->name, 'email' => $result['admin']->email],
            'webhook' => [
                'url' => url("/api/v1/integrations/webhooks/{$result['webhook_key']}/leads"),
                'secret' => $result['webhook_secret'],
                'notice' => 'Copy this secret now — it cannot be shown again.',
            ],
        ]);
    }

    public function show(string $uuid)
    {
        return $this->context->withoutScope(function () use ($uuid) {
            $tenant = Tenant::with('branding', 'settings')
                ->withCount(['users', 'leads'])
                ->where('uuid', $uuid)->firstOrFail();

            return ApiResponse::success([
                'tenant' => $this->present($tenant),
                'users' => User::where('tenant_id', $tenant->id)->get()
                    ->map(fn ($u) => [
                        'id' => $u->uuid, 'name' => $u->name, 'email' => $u->email,
                        'role' => $u->role?->value, 'is_active' => $u->is_active,
                        'last_login_at' => $u->last_login_at?->toIso8601String(),
                    ]),
            ]);
        });
    }

    public function update(Request $request, string $uuid)
    {
        return $this->context->withoutScope(function () use ($request, $uuid) {
            $tenant = Tenant::with('branding', 'settings')->where('uuid', $uuid)->firstOrFail();

            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:255'],
                'plan' => ['sometimes', 'string', 'max:40'],
                'subscription_status' => ['sometimes', 'in:trialing,active,past_due,cancelled'],
                'trial_ends_at' => ['nullable', 'date'],
                'subscription_ends_at' => ['nullable', 'date'],
                'is_active' => ['sometimes', 'boolean'],
                'branding' => ['sometimes', 'array'],
                'branding.company_name' => ['sometimes', 'string', 'max:255'],
                'branding.system_name' => ['sometimes', 'string', 'max:255'],
                'branding.logo_url' => ['nullable', 'url', 'max:2048'],
                'branding.primary_color' => ['sometimes', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'branding.secondary_color' => ['sometimes', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            ]);

            $branding = $data['branding'] ?? null;
            unset($data['branding']);

            $tenant->update($data);

            if ($branding) {
                $tenant->branding->update($branding);
            }

            // Suspending a tenant must cut off every live session immediately.
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                PersonalAccessToken::where('tokenable_type', User::class)
                    ->whereIn('tokenable_id', User::where('tenant_id', $tenant->id)->pluck('id'))
                    ->delete();
            }

            $this->audit->log('tenant.updated', $request->user(), $tenant, ['fields' => array_keys($data)]);

            return ApiResponse::success(['tenant' => $this->present($tenant->fresh(['branding'])->loadCount(['users', 'leads']))]);
        });
    }

    /** Adds an extra admin to an existing tenant (e.g. ownership handover). */
    public function createAdmin(Request $request, string $uuid)
    {
        return $this->context->withoutScope(function () use ($request, $uuid) {
            $tenant = Tenant::where('uuid', $uuid)->firstOrFail();

            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255',
                    Rule::unique('users', 'email')->where('tenant_id', $tenant->id)->whereNull('deleted_at')],
                'password' => ['required', 'string', 'min:8'],
            ]);

            $admin = User::create([
                'tenant_id' => $tenant->id,
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'password' => $data['password'],
                'role' => Role::Admin,
                'is_active' => true,
            ]);

            $this->audit->log('tenant.admin_created', $request->user(), $admin, ['tenant' => $tenant->slug]);

            return ApiResponse::created(['user' => ['id' => $admin->uuid, 'name' => $admin->name, 'email' => $admin->email]]);
        });
    }

    private function present(Tenant $tenant): array
    {
        return [
            'id' => $tenant->uuid,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'system_name' => $tenant->branding?->system_name,
            'logo_url' => $tenant->branding?->logo_url,
            'primary_color' => $tenant->branding?->primary_color,
            'plan' => $tenant->plan,
            'subscription_status' => $tenant->subscription_status,
            'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
            'subscription_ends_at' => $tenant->subscription_ends_at?->toIso8601String(),
            'is_active' => $tenant->is_active,
            'users_count' => $tenant->users_count ?? null,
            'leads_count' => $tenant->leads_count ?? null,
            'created_at' => $tenant->created_at?->toIso8601String(),
        ];
    }
}
