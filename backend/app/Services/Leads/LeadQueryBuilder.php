<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\User;
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

        // If it looks like a phone number, match on the normalized column so
        // "0512345678" finds a lead stored as "+966512345678".
        $normalized = $this->phones->normalize($term);

        $query->where(function (Builder $q) use ($term, $normalized) {
            $q->where('full_name', 'ilike', '%'.$term.'%')
                ->orWhere('email', 'ilike', '%'.$term.'%')
                ->orWhere('phone', 'ilike', '%'.$term.'%');

            if ($normalized) {
                $q->orWhere('phone_normalized', $normalized);
            }
        });
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        $query
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', (array) $request->input('status')))
            ->when($request->filled('temperature'), fn ($q) => $q->whereIn('temperature', (array) $request->input('temperature')))
            ->when($request->filled('platform'), fn ($q) => $q->whereIn('platform', (array) $request->input('platform')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->input('source')))
            ->when($request->filled('campaign_name'), fn ($q) => $q->where('campaign_name', $request->input('campaign_name')))
            ->when($request->filled('project_id'), fn ($q) => $q->whereHas('project', fn ($p) => $p->whereIn('uuid', (array) $request->input('project_id'))))
            ->when($request->filled('assigned_to'), function ($q) use ($request) {
                $values = (array) $request->input('assigned_to');

                if (in_array('unassigned', $values, true)) {
                    return $q->where(fn ($inner) => $inner
                        ->whereNull('assigned_to')
                        ->orWhereHas('assignee', fn ($u) => $u->whereIn('uuid', array_diff($values, ['unassigned']))));
                }

                return $q->whereHas('assignee', fn ($u) => $u->whereIn('uuid', $values));
            })
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('date_to')))
            ->when($request->boolean('overdue'), fn ($q) => $q->overdueFollowUp())
            ->when($request->boolean('unassigned'), fn ($q) => $q->whereNull('assigned_to'))
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
