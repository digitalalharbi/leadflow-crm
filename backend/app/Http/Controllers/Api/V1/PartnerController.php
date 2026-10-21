<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Permissions;
use App\Domain\Partners\CommissionLedger;
use App\Domain\Partners\PartnerStats;
use App\Domain\Reports\ReportTemplates;
use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerCommissionPayment;
use App\Models\ReportShare;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The people outside the team who bring customers, what each has brought, and what each is owed. */
class PartnerController extends Controller
{
    public function __construct(private PartnerStats $stats, private CommissionLedger $ledger, private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Partner::class);

        $f = $request->validate(['type' => ['nullable', Rule::in(Partner::TYPES)], 'days' => ['nullable', 'integer', 'min:1', 'max:365'], 'search' => ['nullable', 'string', 'max:100']]);

        $partners = Partner::query()
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['search'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('name', 'ilike', "%{$v}%")->orWhere('company', 'ilike', "%{$v}%")))
            ->orderBy('name')->get();

        $stats = $this->stats->for($partners->pluck('id')->all(), isset($f['days']) ? now()->subDays((int) $f['days'])->startOfDay() : null);
        $ledger = $this->ledger->for($partners->pluck('id')->all());

        return ApiResponse::success([
            'partners' => $partners->map(fn (Partner $p) => $this->present($p) + ['stats' => $stats[$p->id], 'ledger' => $ledger[$p->id]])->values(),
            'types' => Partner::TYPES,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Partner::class);

        $partner = Partner::create($this->validated($request) + ['created_by' => $request->user()->id]);
        $this->audit->log('partner.created', $request->user(), $partner, ['after' => $partner->only(['name', 'type', 'commission_type', 'commission_percent', 'commission_fixed_minor', 'show_revenue'])]);

        return ApiResponse::created(['partner' => $this->present($partner) + ['stats' => $this->stats->for([$partner->id])[$partner->id], 'ledger' => $this->ledger->for([$partner->id])[$partner->id]]]);
    }

    public function update(Request $request, string $uuid)
    {
        $partner = Partner::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $partner);

        $before = $partner->only(['name', 'type', 'commission_type', 'commission_percent', 'commission_fixed_minor', 'show_revenue', 'is_active']);
        $partner->update($this->validated($request, partial: true));
        $this->audit->log('partner.updated', $request->user(), $partner, ['before' => $before, 'after' => $partner->only(array_keys($before))]);

        return ApiResponse::success(['partner' => $this->present($partner) + ['stats' => $this->stats->for([$partner->id])[$partner->id], 'ledger' => $this->ledger->for([$partner->id])[$partner->id]]]);
    }

    /** What the partner brought, what it earns them, what has been paid, and the deals and payments behind it. */
    public function statement(Request $request, string $uuid)
    {
        $partner = Partner::where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $partner);
        $this->mayHandleMoney($request);

        $days = (int) $request->query('days', 90);
        $since = now()->subDays(max(1, min(365, $days)))->startOfDay();

        return ApiResponse::success([
            'partner' => $this->present($partner),
            'terms' => CommissionLedger::terms($partner),
            'stats' => $this->stats->for([$partner->id], $since)[$partner->id],
            'ledger' => $this->ledger->for([$partner->id])[$partner->id],
            'deals' => $this->ledger->deals($partner),
            'commission_payments' => $partner->payments()->orderByDesc('paid_on')->orderByDesc('id')->get()->map(fn (PartnerCommissionPayment $p) => $this->payment($p))->all(),
            'days' => $days,
        ]);
    }

    public function pay(Request $request, string $uuid)
    {
        $partner = Partner::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $partner);
        $this->mayHandleMoney($request);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $payment = PartnerCommissionPayment::create([
            'partner_id' => $partner->id,
            'amount_minor' => (int) round(((float) $data['amount']) * 100),
            'paid_on' => $data['paid_on'] ?? now()->toDateString(),
            'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);
        $this->audit->log('partner.commission_paid', $request->user(), $partner, ['amount_minor' => $payment->amount_minor, 'reference' => $payment->reference]);

        return ApiResponse::created(['payment' => $this->payment($payment), 'ledger' => $this->ledger->for([$partner->id])[$partner->id]]);
    }

    public function voidPayment(Request $request, string $uuid, string $payment)
    {
        $partner = Partner::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $partner);
        $this->mayHandleMoney($request);

        $row = $partner->payments()->where('uuid', $payment)->firstOrFail();
        $this->audit->log('partner.commission_payment_removed', $request->user(), $partner, ['amount_minor' => $row->amount_minor, 'reference' => $row->reference, 'paid_on' => $row->paid_on?->toDateString()]);
        $row->delete();

        return ApiResponse::success(['ledger' => $this->ledger->for([$partner->id])[$partner->id]]);
    }

    /**
     * The partner's own statement, behind a link only they hold: their leads down the funnel and what
     * they are owed and have been paid — never the company's spend, other partners or the team.
     */
    public function statementLink(Request $request, string $uuid)
    {
        $partner = Partner::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $partner);
        $this->mayHandleMoney($request);

        $share = ReportShare::query()->where('partner_id', $partner->id)->where('template', 'statement')->whereNull('revoked_at')->latest('id')->first();

        if ($share === null) {
            $layout = ReportTemplates::layoutFor('statement');
            $share = new ReportShare([
                'name' => $partner->name, 'template' => 'statement', 'layout' => $layout,
                'sections' => collect($layout)->mapWithKeys(fn ($row) => [$row['key'] => $row['on']])->all(),
                'days' => 90, 'partner_id' => $partner->id, 'refresh_seconds' => 300, 'created_by' => $request->user()->id,
            ]);
            $share->rotateToken();
            $share->save();
            $this->audit->log('report_share.created', $request->user(), $share, ['after' => ['name' => $share->name, 'template' => 'statement', 'partner_id' => $partner->id]]);
        }

        return ApiResponse::success(['share' => ['id' => $share->uuid, 'url' => $share->url(), 'status' => $share->status(), 'view_count' => $share->view_count, 'last_viewed_at' => $share->last_viewed_at?->toIso8601String()]]);
    }

    /** Money is for people who may see money. */
    private function mayHandleMoney(Request $request): void
    {
        abort_unless(Permissions::has($request->user(), 'revenue.view'), 403);
    }

    /** @return array<string,mixed> */
    private function payment(PartnerCommissionPayment $p): array
    {
        return ['id' => $p->uuid, 'amount' => round($p->amount_minor / 100, 2), 'paid_on' => $p->paid_on?->toDateString(), 'reference' => $p->reference, 'note' => $p->note];
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

        $data = $request->validate([
            'name' => [$req, 'string', 'max:160'],
            'type' => [$req, Rule::in(Partner::TYPES)],
            'company' => ['nullable', 'string', 'max:160'],
            'contact_name' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'commission_type' => ['sometimes', Rule::in(Partner::COMMISSION_TYPES)],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Per won deal, in the workspace's currency (kept in minor units).
            'commission_fixed' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'show_revenue' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (array_key_exists('commission_fixed', $data)) {
            $data['commission_fixed_minor'] = $data['commission_fixed'] === null ? null : (int) round(((float) $data['commission_fixed']) * 100);
            unset($data['commission_fixed']);
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function present(Partner $p): array
    {
        return [
            'id' => $p->uuid, 'name' => $p->name, 'type' => $p->type, 'company' => $p->company, 'contact_name' => $p->contact_name,
            'email' => $p->email, 'phone' => $p->phone,
            'commission_type' => $p->commission_type ?: 'percentage',
            'commission_percent' => $p->commission_percent !== null ? (float) $p->commission_percent : null,
            'commission_fixed' => $p->commission_fixed_minor !== null ? round($p->commission_fixed_minor / 100, 2) : null,
            'show_revenue' => (bool) $p->show_revenue, 'is_active' => (bool) $p->is_active, 'notes' => $p->notes,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
