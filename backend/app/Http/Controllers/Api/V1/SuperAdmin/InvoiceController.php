<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Domain\Platform\PlatformAudit;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Tenant;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Invoices recorded by hand while no payment provider issues them: what was billed, for which period, and whether it was
 * paid. Revenue on the owner's dashboard counts paid invoices; nothing here is estimated.
 */
class InvoiceController extends Controller
{
    public function __construct(private PlatformAudit $audit) {}

    public function store(Request $request, string $uuid)
    {
        $tenant = Tenant::where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['required', 'string', 'size:3'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'status' => ['sometimes', Rule::in(['draft', 'issued', 'paid'])],
            'provider_ref' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'plan_id' => ['nullable', 'uuid'],
        ]);

        $invoice = DB::transaction(function () use ($data, $tenant, $request) {
            $year = now()->year;
            // One numbering at a time: two owners issuing at once must not draw the same number.
            DB::select('select pg_advisory_xact_lock(?)', [crc32('invoice-numbers')]);
            $sequence = Invoice::withoutGlobalScopes()->where('number', 'like', "INV-{$year}-%")->count() + 1;
            $status = $data['status'] ?? 'issued';

            return Invoice::create([
                'tenant_id' => $tenant->id,
                'plan_id' => isset($data['plan_id']) ? Plan::where('uuid', $data['plan_id'])->value('id') : $tenant->plan_id,
                'number' => sprintf('INV-%d-%05d', $year, $sequence),
                'amount' => $data['amount'],
                'currency' => strtoupper($data['currency']),
                'period_start' => $data['period_start'] ?? null,
                'period_end' => $data['period_end'] ?? null,
                'status' => $status,
                'provider' => $tenant->billing_provider ?? 'manual',
                'provider_ref' => $data['provider_ref'] ?? null,
                'issued_at' => $status === 'draft' ? null : now(),
                'paid_at' => $status === 'paid' ? now() : null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);
        });

        $this->audit->log('invoice.created', $request->user(), $tenant, $invoice, ['number' => $invoice->number, 'amount' => $invoice->amount, 'status' => $invoice->status]);

        return ApiResponse::created(['invoice' => self::present($invoice)]);
    }

    public function transition(Request $request, string $uuid, string $action)
    {
        $invoice = Invoice::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();
        abort_unless(in_array($action, ['paid', 'void', 'issued'], true), 404);

        $invoice->forceFill([
            'status' => $action,
            'paid_at' => $action === 'paid' ? now() : null,
            'issued_at' => $invoice->issued_at ?? ($action !== 'void' ? now() : null),
        ])->save();

        $this->audit->log('invoice.'.$action, $request->user(), Tenant::find($invoice->tenant_id), $invoice, ['number' => $invoice->number]);

        return ApiResponse::success(['invoice' => self::present($invoice)]);
    }

    public static function present(Invoice $invoice): array
    {
        return [
            'id' => $invoice->uuid,
            'number' => $invoice->number,
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
            'period_start' => $invoice->period_start?->toDateString(),
            'period_end' => $invoice->period_end?->toDateString(),
            'status' => $invoice->status,
            'provider' => $invoice->provider,
            'provider_ref' => $invoice->provider_ref,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'notes' => $invoice->notes,
        ];
    }
}
