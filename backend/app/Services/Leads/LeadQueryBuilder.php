<?php

namespace App\Services\Leads;

use App\Enums\LeadStatus;
use App\Models\ConversionExport;
use App\Models\Lead;
use App\Models\User;
use App\Support\Uuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Shared filter/sort/search logic for the leads table, the pipeline board and
 * every report, so "the filters" mean the same thing everywhere.
 */
class LeadQueryBuilder
{
    private const SORTABLE = [
        'created_at', 'updated_at', 'full_name', 'score',
        'next_follow_up_at', 'last_contact_at', 'status',
    ];

    public function __construct(private PhoneNormalizer $phones) {}

    public function build(Request $request, User $user): Builder
    {
        $query = Lead::query()->visibleTo($user);

        $this->applySearch($query, $request->string('search')->toString());
        $this->applyFilters($query, $request);
        $this->applySort($query, $request);

        return $query;
    }

    private function applySearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        /*
         * A term made of digits is a phone number, and nobody searching for one
         * wants it matched against names. Going straight to the normalised
         * column turns a three-column ILIKE scan into an index lookup on
         * (tenant_id, phone_normalized) — about 200ms to under 1ms at 10k rows,
         * and the gap widens with volume.
         */
        if ($this->looksLikePhone($term)) {
            $normalized = $this->phones->normalize($term);
            // Via the normaliser, so ٠٥١٢٣٤٥٦٧٨ yields digits like 0512345678 does.
            $digits = $this->phones->digits($term);

            $query->where(function (Builder $q) use ($normalized, $digits) {
                if ($normalized) {
                    $q->where('phone_normalized', $normalized);
                }

                // Fallback for a partial number the rep half-remembers.
                $q->orWhere('phone', 'like', '%'.$digits.'%');
            });

            return;
        }

        $query->where(function (Builder $q) use ($term) {
            $q->where('full_name', 'ilike', '%'.$term.'%')
                ->orWhere('email', 'ilike', '%'.$term.'%');
        });
    }

    /**
     * A phone-shaped term: digits — Latin or Arabic-Indic — plus the punctuation
     * people type into a phone field, and no letters at all.
     */
    private function looksLikePhone(string $term): bool
    {
        if (preg_match('/\p{L}/u', $term)) {
            return false;
        }

        return strlen($this->phones->digits($term)) >= 4;
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        $query
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', (array) $request->input('status')))
            ->when($request->filled('temperature'), fn ($q) => $q->whereIn('temperature', (array) $request->input('temperature')))
            ->when($request->filled('platform'), fn ($q) => $q->whereIn('platform', (array) $request->input('platform')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->input('source')))
            ->when($request->filled('campaign_name'), fn ($q) => $q->where('campaign_name', $request->input('campaign_name')))
            ->when($request->filled('project_id'), fn ($q) => $q->whereHas('project', fn ($p) => $p->whereIn('uuid', Uuid::onlyValid($request->input('project_id')))))
            ->when($request->filled('assigned_to'), function ($q) use ($request) {
                $values = (array) $request->input('assigned_to');
                $ids = Uuid::onlyValid($values);

                if (in_array('unassigned', $values, true)) {
                    return $q->where(fn ($inner) => $inner
                        ->whereNull('assigned_to')
                        ->orWhereHas('assignee', fn ($u) => $u->whereIn('uuid', $ids)));
                }

                return $q->whereHas('assignee', fn ($u) => $u->whereIn('uuid', $ids));
            })
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('date_to')))
            ->when($request->boolean('overdue'), fn ($q) => $q->overdueFollowUp())
            ->when($request->boolean('unassigned'), fn ($q) => $q->whereNull('assigned_to'))
            // Arrived and still untouched past the workspace's own target. The
            // dashboard panel links here, so the list has to mean the same thing
            // the number did.
            // Leads who answered us — the first stage the customer controls.
            ->when($request->boolean('replied'), fn ($q) => $q->whereNotNull('first_response_at'))
            // The project cards and the funnel both link here.
            ->when($request->filled('qualification_status'), fn ($q) => $q->whereIn(
                'qualification_status',
                (array) $request->input('qualification_status'),
            ))
            ->when($request->boolean('awaiting_contact'), function (Builder $q) use ($request) {
                $minutes = (int) ($request->user()?->tenant?->settings?->first_contact_target_minutes ?? 30);

                $q->whereNull('first_contact_at')
                    ->where('status', LeadStatus::New->value)
                    ->where('leads.created_at', '<=', now()->subMinutes($minutes));
            })
            /*
             * The drill-down behind the data quality screen. One parameter
             * rather than six, so a number on that screen opens exactly the
             * rows it counted — a count whose list disagrees with it is worse
             * than no count, because somebody will trust one of the two.
             */
            ->when($request->filled('data_issue'), fn ($q) => match ($request->input('data_issue')) {
                'unreachable_phones' => $q->whereNull('phone_normalized')
                    ->where('status', '!=', LeadStatus::Invalid->value),
                // By email: a duplicate phone cannot be inserted at all.
                'duplicates' => $q->whereNotNull('email')->where('email', '!=', '')
                    ->whereIn('email', function ($sub) {
                        $sub->from('leads')
                            ->select('email')
                            ->whereNull('deleted_at')
                            ->whereNotNull('email')
                            ->groupBy('tenant_id', 'email')
                            ->havingRaw('count(*) > 1');
                    }),
                'missing_attribution' => $q->whereNull('platform')->whereNull('source'),
                /*
                 * Open *and* unassigned. The plain `unassigned` filter means
                 * something looser, and the data quality count means this — two
                 * numbers that disagree about the same word is how a dashboard
                 * stops being believed.
                 */
                'unassigned' => $q->whereNull('assigned_to')
                    ->whereIn('status', LeadStatus::openValues()),
                // The leads behind a sale no ad platform can be told about.
                'unmatchable_conversions' => $q->whereIn('id', function ($sub) {
                    $sub->from('conversion_exports')
                        ->select('lead_id')
                        ->where('status', ConversionExport::UNMATCHABLE);
                }),
                'missing_campaign' => $q->whereNotNull('platform')
                    ->whereNull('campaign_name')->whereNull('campaign_id'),
                'stale' => $q->whereIn('status', LeadStatus::openValues())
                    ->where('leads.created_at', '<', now()->subDays(14))
                    ->where(fn ($inner) => $inner->whereNull('last_contact_at')
                        ->orWhere('last_contact_at', '<', now()->subDays(14))),
                default => $q,
            })
            ->when($request->filled('follow_up'), function ($q) use ($request) {
                return match ($request->input('follow_up')) {
                    'today' => $q->whereBetween('next_follow_up_at', [now()->startOfDay(), now()->endOfDay()]),
                    'overdue' => $q->overdueFollowUp(),
                    'upcoming' => $q->where('next_follow_up_at', '>', now()->endOfDay()),
                    default => $q,
                };
            });
    }

    private function applySort(Builder $query, Request $request): void
    {
        $sort = $request->string('sort', 'created_at')->toString();
        $direction = $request->string('direction', 'desc')->toString() === 'asc' ? 'asc' : 'desc';

        if (! in_array($sort, self::SORTABLE, true)) {
            $sort = 'created_at';
        }

        $query->orderBy($sort, $direction)->orderBy('id', 'desc');
    }
}
