<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\QualificationStatus;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\Leads\LeadQueryBuilder;
use App\Support\ApiResponse;
use App\Support\CsvWriter;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The two reports a developer actually buys this product for.
 *
 * "Campaign quality" answers which spend brings buyers rather than clicks: a
 * campaign with 500 leads and one appointment is worse than one with 300 leads
 * and fifteen, and nothing in a lead count shows that.
 *
 * "Performance" answers how fast and how well the team works what arrives.
 */
class SalesPerformanceController extends Controller
{
    public function __construct(private LeadQueryBuilder $queries) {}

    public function campaignQuality(Request $request)
    {
        $this->authorize('export', Lead::class);

        $rows = $this->campaignRows($request);

        return ApiResponse::success([
            'rows' => $rows,
            'totals' => $this->summarise($rows),
        ]);
    }

    public function exportCampaignQuality(Request $request): StreamedResponse
    {
        $this->authorize('export', Lead::class);

        $rows = $this->campaignRows($request);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            $csv = CsvWriter::open($out);
            $csv->writeRow([
                'Platform', 'Campaign', 'Leads', 'Contacted', 'Replied',
                'Qualified', 'Hot', 'Appointments', 'Won',
                'Contact %', 'Reply %', 'Qualification %', 'Appointment %', 'Win %',
            ]);

            foreach ($rows as $row) {
                $csv->writeRow([
                    $row['platform'], $row['campaign'], $row['leads'], $row['contacted'], $row['replied'],
                    $row['qualified'], $row['hot'], $row['appointments'], $row['won'],
                    $row['contact_rate'], $row['reply_rate'], $row['qualification_rate'],
                    $row['appointment_rate'], $row['win_rate'],
                ]);
            }

            fclose($out);
        }, 'campaign-quality-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Headline numbers plus the rates and the average time to first contact. */
    public function performance(Request $request)
    {
        $this->authorize('export', Lead::class);

        $row = $this->queries->build($request, $request->user())->reorder()
            ->select($this->countExpressions())
            ->selectRaw(
                // Seconds from the lead arriving to someone first reaching out.
                // Averaged over the leads that were contacted at all — including
                // the untouched ones as zero would flatter a slow team.
                'avg(extract(epoch from (leads.first_contact_at - leads.created_at)))
                   filter (where leads.first_contact_at is not null) as avg_first_contact_seconds,
                 avg(extract(epoch from (leads.first_response_at - leads.first_contact_at)))
                   filter (where leads.first_response_at is not null and leads.first_contact_at is not null) as avg_first_reply_seconds'
            )
            ->first();

        $counts = $this->normaliseCounts($row);

        return ApiResponse::success([
            'counts' => $counts,
            'rates' => $this->rates($counts),
            'timing' => [
                'avg_first_contact_seconds' => $row->avg_first_contact_seconds !== null
                    ? (int) round((float) $row->avg_first_contact_seconds) : null,
                'avg_first_reply_seconds' => $row->avg_first_reply_seconds !== null
                    ? (int) round((float) $row->avg_first_reply_seconds) : null,
            ],
        ]);
    }

    /**
     * Per-rep metrics — measurements, deliberately not a ranking.
     *
     * A league table of people is easy to build and corrosive to use: it
     * rewards whoever got the best leads that month and tells a manager
     * nothing about why. These are the same funnel measures the rest of the
     * product uses, per owner, plus how fast each one answers — which is the
     * one number a rep genuinely controls.
     */
    public function salesTeam(Request $request)
    {
        $this->authorize('export', Lead::class);

        $tenantId = $request->user()->tenant_id;

        $rows = $this->queries->build($request, $request->user())->reorder()
            ->leftJoin('users', function ($join) use ($tenantId) {
                $join->on('users.id', '=', 'leads.assigned_to')
                    ->where('users.tenant_id', '=', $tenantId)
                    ->whereNull('users.deleted_at');
            })
            ->select([
                DB::raw("coalesce(users.name, '—') as name"),
                DB::raw('users.uuid as user_id'),
                ...$this->countExpressions(),
            ])
            ->selectRaw(
                'avg(extract(epoch from (leads.first_contact_at - leads.created_at)))
                   filter (where leads.first_contact_at is not null) as avg_first_contact_seconds'
            )
            ->groupBy('users.name', 'users.uuid')
            ->orderByDesc(DB::raw('count(*)'))
            ->limit(50)
            ->get();

        return ApiResponse::success([
            'rows' => $rows->map(function ($row) {
                $counts = $this->normaliseCounts($row);

                return array_merge([
                    'user_id' => $row->user_id,
                    'name' => (string) $row->name,
                    'avg_first_contact_seconds' => $row->avg_first_contact_seconds !== null
                        ? (int) round((float) $row->avg_first_contact_seconds)
                        : null,
                ], $counts, $this->rates($counts));
            })->all(),
        ]);
    }

    private function campaignRows(Request $request): array
    {
        $rows = $this->queries->build($request, $request->user())->reorder()
            ->select([
                DB::raw("coalesce(leads.platform, '—') as platform"),
                DB::raw("coalesce(leads.campaign_name, '—') as campaign"),
                ...$this->countExpressions(),
            ])
            ->groupBy(DB::raw("coalesce(leads.platform, '—'), coalesce(leads.campaign_name, '—')"))
            ->orderByDesc(DB::raw('count(*)'))
            ->limit(200)
            ->get();

        return $rows->map(function ($row) {
            $counts = $this->normaliseCounts($row);

            return array_merge([
                'platform' => (string) $row->platform,
                'campaign' => (string) $row->campaign,
            ], $counts, $this->rates($counts));
        })->all();
    }

    /** @return array<int, Expression> */
    private function countExpressions(): array
    {
        return [
            DB::raw('count(*) as leads'),
            /*
             * Reached, not recorded. A rep who drags a lead to "contacted"
             * without the system ever stamping a first-contact time has still
             * contacted them, and counting only the stamp put a rate of 0% on
             * the same screen as a funnel reading 74% — two numbers, one word,
             * both claiming to be the truth.
             */
            DB::raw(sprintf(
                "count(*) filter (where leads.first_contact_at is not null or leads.status <> '%s') as contacted",
                LeadStatus::New->value,
            )),
            // Replies are the exception: there is no "replied" stage to reach,
            // so an inbound message is the only evidence there is.
            DB::raw('count(*) filter (where leads.first_response_at is not null) as replied'),
            DB::raw(sprintf(
                "count(*) filter (where leads.qualification_status = '%s'
                   or leads.status in ('%s', '%s', '%s')) as qualified",
                QualificationStatus::Qualified->value,
                LeadStatus::Qualified->value,
                LeadStatus::Appointment->value,
                LeadStatus::Won->value,
            )),
            DB::raw(sprintf(
                "count(*) filter (where leads.temperature = '%s' and leads.status not in ('%s', '%s')) as hot",
                LeadTemperature::Hot->value,
                LeadStatus::Won->value,
                LeadStatus::Lost->value,
            )),
            // Reaching the appointment stage, not sitting in it: a lead that was
            // booked and then bought must still count as an appointment, or the
            // best campaigns would show the fewest.
            DB::raw(sprintf(
                "count(*) filter (where leads.status in ('%s', '%s')) as appointments",
                LeadStatus::Appointment->value,
                LeadStatus::Won->value,
            )),
            DB::raw(sprintf("count(*) filter (where leads.status = '%s') as won", LeadStatus::Won->value)),
            DB::raw(sprintf("count(*) filter (where leads.status = '%s') as lost", LeadStatus::Lost->value)),
        ];
    }

    private function normaliseCounts(object $row): array
    {
        return [
            'leads' => (int) $row->leads,
            'contacted' => (int) $row->contacted,
            'replied' => (int) $row->replied,
            'qualified' => (int) $row->qualified,
            'hot' => (int) $row->hot,
            'appointments' => (int) $row->appointments,
            'won' => (int) $row->won,
            'lost' => (int) $row->lost,
        ];
    }

    private function rates(array $c): array
    {
        $rate = fn (int $part) => $c['leads'] > 0 ? round($part / $c['leads'] * 100, 1) : 0.0;

        return [
            'contact_rate' => $rate($c['contacted']),
            'reply_rate' => $rate($c['replied']),
            'qualification_rate' => $rate($c['qualified']),
            'appointment_rate' => $rate($c['appointments']),
            'win_rate' => $rate($c['won']),
        ];
    }

    private function summarise(array $rows): array
    {
        $sum = fn (string $key) => array_sum(array_column($rows, $key));

        $counts = [
            'leads' => $sum('leads'),
            'contacted' => $sum('contacted'),
            'replied' => $sum('replied'),
            'qualified' => $sum('qualified'),
            'hot' => $sum('hot'),
            'appointments' => $sum('appointments'),
            'won' => $sum('won'),
            'lost' => $sum('lost'),
        ];

        return array_merge($counts, $this->rates($counts));
    }
}
