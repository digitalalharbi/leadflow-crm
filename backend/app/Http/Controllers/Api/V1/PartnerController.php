<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Partners\PartnerStats;
use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The people outside the team who bring customers, what each has brought, and what each is owed. */
class PartnerController extends Controller
{
    public function __construct(private PartnerStats $stats, private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Partner::class);

        $f = $request->validate(['type' => ['nullable', Rule::in(Partner::TYPES)], 'days' => ['nullable', 'integer', 'min:1', 'max:365'], 'search' => ['nullable', 'string', 'max:100']]);

        $partners = Partner::query()
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['search'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('name', 'ilike', "%{$v}%")->orWhere('company', 'ilike', "%{$v}%")))
            ->orderBy('name')->get();

        $stats = $this->stats->for($partners->pluck('id')->all(), isset($f['days']) ? now()->subDays((int) $f['days'])->startOfDay() : null);

        return ApiResponse::success([
            'partners' => $partners->map(fn (Partner $p) => $this->present($p) + ['stats' => $stats[$p->id]])->values(),
            'types' => Partner::TYPES,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Partner::class);

        $partner = Partner::create($this->validated($request) + ['created_by' => $request->user()->id]);
        $this->audit->log('partner.created', $request->user(), $partner, ['after' => $partner->only(['name', 'type', 'commission_percent', 'show_revenue'])]);

        return ApiResponse::created(['partner' => $this->present($partner) + ['stats' => $this->stats->for([$partner->id])[$partner->id]]]);
    }

    public function update(Request $request, string $uuid)
    {
        $partner = Partner::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $partner);

        $before = $partner->only(['name', 'type', 'commission_percent', 'show_revenue', 'is_active']);
        $partner->update($this->validated($request, partial: true));
        $this->audit->log('partner.updated', $request->user(), $partner, ['before' => $before, 'after' => $partner->only(array_keys($before))]);

        return ApiResponse::success(['partner' => $this->present($partner) + ['stats' => $this->stats->for([$partner->id])[$partner->id]]]);
    }

    public function destroy(Request $request, string $uuid)
    {
        $partner = Partner::where('uuid', $uuid)->firstOrFail();
        $this->authorize('delete', $partner);

        $this->audit->log('partner.deleted', $request->user(), $partner, ['before' => ['name' => $partner->name]]);
        // Soft-deleted: the leads it brought keep their credit.
        $partner->delete();

        return ApiResponse::success(null, 'Partner deleted.');
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$req, 'string', 'max:160'],
            'type' => [$req, Rule::in(Partner::TYPES)],
            'company' => ['nullable', 'string', 'max:160'],
            'contact_name' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'show_revenue' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /** @return array<string,mixed> */
    private function present(Partner $p): array
    {
        return [
            'id' => $p->uuid, 'name' => $p->name, 'type' => $p->type, 'company' => $p->company, 'contact_name' => $p->contact_name,
            'email' => $p->email, 'phone' => $p->phone,
            'commission_percent' => $p->commission_percent !== null ? (float) $p->commission_percent : null,
            'show_revenue' => (bool) $p->show_revenue, 'is_active' => (bool) $p->is_active, 'notes' => $p->notes,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
