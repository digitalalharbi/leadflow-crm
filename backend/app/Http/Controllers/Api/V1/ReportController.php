<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\Leads\LeadQueryBuilder;
use App\Support\ApiResponse;
use App\Support\CsvWriter;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One endpoint, several report shapes. Each report returns the same row format
 * — { key, label, total, won, qualified, conversion_rate } — so the UI renders
 * them all with a single table component and CSV export is uniform.
 */
class ReportController extends Controller
{
    private const REPORTS = [
        'platform', 'campaign', 'project', 'sales_rep', 'status', 'lost_reason', 'source',
    ];

    public function __construct(
        private LeadQueryBuilder $queries,
        private TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('export', Lead::class);

        $type = $request->string('type', 'platform')->toString();

        if (! in_array($type, self::REPORTS, true)) {
            return ApiResponse::error('Unknown report type.', ['type' => self::REPORTS], 422);
        }

        $rows = $this->rows($type, $request);

        return ApiResponse::success([
            'type' => $type,
            'rows' => $rows,
            'totals' => $this->totals($rows),
            'available' => self::REPORTS,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('export', Lead::class);

        $type = $request->string('type', 'platform')->toString();

        if (! in_array($type, self::REPORTS, true)) {
            abort(422, 'Unknown report type.');
        }

        $rows = $this->rows($type, $request);
        $filename = "report-{$type}-".now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            $csv = CsvWriter::open($out);
            $csv->writeRow(['Label', 'Total Leads', 'Qualified', 'Appointments', 'Won', 'Lost', 'Conversion %']);

            foreach ($rows as $row) {
                $csv->writeRow([
                    $row['label'], $row['total'], $row['qualified'],
                    $row['appointments'], $row['won'], $row['lost'], $row['conversion_rate'],
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function rows(string $type, Request $request): array
    {
        $base = $this->queries->build($request, $request->user())->reorder();

        $tenantId = $this->context->id();

        // Every join carries its own tenant predicate. The base query only scopes
        // `leads`; without this, a lead row pointing at a foreign id would render
        // the other tenant's label. Soft-deleted rows are excluded too, so a
        // removed project or user stops appearing in reports.
        [$select, $groupBy, $joins] = match ($type) {
            'platform' => ['leads.platform', 'leads.platform', null],
            'source' => ['leads.source', 'leads.source', null],
            'campaign' => ['leads.campaign_name', 'leads.campaign_name', null],
            'status' => ['leads.status', 'leads.status', null],
            'project' => ['projects.name', 'projects.name', fn (Builder $q) => $q->leftJoin('projects', function ($join) use ($tenantId) {
                $join->on('projects.id', '=', 'leads.project_id')
                    ->where('projects.tenant_id', '=', $tenantId)
                    ->whereNull('projects.deleted_at');
            })],
            'sales_rep' => ['users.name', 'users.name', fn (Builder $q) => $q->leftJoin('users', function ($join) use ($tenantId) {
                $join->on('users.id', '=', 'leads.assigned_to')
                    ->where('users.tenant_id', '=', $tenantId)
                    ->whereNull('users.deleted_at');
            })],
            'lost_reason' => ['lost_reasons.label', 'lost_reasons.label', fn (Builder $q) => $q->leftJoin('lost_reasons', function ($join) use ($tenantId) {
                $join->on('lost_reasons.id', '=', 'leads.lost_reason_id')
                    ->where('lost_reasons.tenant_id', '=', $tenantId);
            })],
        };

        if ($joins) {
            $joins($base);
        }

        if ($type === 'lost_reason') {
            $base->where('leads.status', LeadStatus::Lost->value);
        }

        $rows = $base
            ->select([
                DB::raw("coalesce({$select}, '—') as label"),
                DB::raw('count(*) as total'),
                DB::raw("count(*) filter (where leads.status = 'qualified') as qualified"),
                DB::raw("count(*) filter (where leads.status = 'appointment') as appointments"),
                DB::raw("count(*) filter (where leads.status = 'won') as won"),
                DB::raw("count(*) filter (where leads.status = 'lost') as lost"),
            ])
            ->groupBy(DB::raw("coalesce({$groupBy}, '—')"))
            ->orderByDesc('total')
            ->limit(200)
            ->get();

        return $rows->map(fn ($row) => [
            'key' => (string) $row->label,
            'label' => (string) $row->label,
            'total' => (int) $row->total,
            'qualified' => (int) $row->qualified,
            'appointments' => (int) $row->appointments,
            'won' => (int) $row->won,
            'lost' => (int) $row->lost,
            'conversion_rate' => $row->total > 0 ? round($row->won / $row->total * 100, 1) : 0.0,
        ])->all();
    }

    private function totals(array $rows): array
    {
        $sum = fn (string $key) => array_sum(array_column($rows, $key));
        $total = $sum('total');
        $won = $sum('won');

        return [
            'total' => $total,
            'qualified' => $sum('qualified'),
            'appointments' => $sum('appointments'),
            'won' => $won,
            'lost' => $sum('lost'),
            'conversion_rate' => $total > 0 ? round($won / $total * 100, 1) : 0.0,
        ];
    }
}
