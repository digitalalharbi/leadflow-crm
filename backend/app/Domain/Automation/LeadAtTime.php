<?php

namespace App\Domain\Automation;

use App\Models\Lead;
use App\Models\LeadActivity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A lead as it was at a moment in the past.
 *
 * The activity log records every stage move and score change with the before
 * and after, and the lead keeps the time of each first — so most of what a
 * rule's conditions read can be put back the way it was: the stage, the score
 * and temperature, whether it had been contacted, answered or qualified, who
 * owned it, whether a person had taken it over. Returned as an unsaved copy;
 * the real lead is never touched.
 */
class LeadAtTime
{
    /** @var Collection<int,Collection<int,LeadActivity>> */
    private Collection $history;

    /** @param  Collection<int,Lead>  $leads */
    public function __construct(Collection $leads)
    {
        $this->history = $leads->isEmpty() ? collect() : LeadActivity::withoutGlobalScopes()
            ->whereIn('lead_id', $leads->pluck('id'))->whereIn('type', ['status_changed', 'score_changed'])
            ->orderBy('created_at')->get(['id', 'lead_id', 'type', 'meta', 'created_at'])->groupBy('lead_id');
    }

    public function at(Lead $lead, Carbon $when): Lead
    {
        $copy = clone $lead;
        $events = $this->history->get($lead->id, collect());
        $before = fn (?Carbon $time) => $time !== null && $time->lte($when);
        $attrs = [];

        // The stage: the last move at or before then, else the first move's "from", else as it stands.
        $moves = $events->where('type', 'status_changed');
        if ($moves->isNotEmpty()) {
            $last = $moves->filter(fn ($e) => $e->created_at->lte($when))->last();
            $attrs['status'] = $last ? ($last->meta['to'] ?? $lead->status?->value) : ($moves->first()->meta['from'] ?? 'new');
        }

        $scores = $events->where('type', 'score_changed');
        if ($scores->isNotEmpty()) {
            $last = $scores->filter(fn ($e) => $e->created_at->lte($when))->last();
            $attrs['score'] = $last ? (int) ($last->meta['to'] ?? $lead->score) : (int) ($scores->first()->meta['from'] ?? $lead->score);
            $attrs['temperature'] = $last ? ($last->meta['temperature'] ?? $lead->temperature?->value) : null;
        }

        // What had not happened yet.
        foreach (['first_contact_at', 'first_response_at', 'last_contact_at', 'qualified_at', 'handed_off_at', 'assigned_at', 'won_at', 'lost_at'] as $column) {
            if ($lead->{$column} !== null && ! $before($lead->{$column})) {
                $attrs[$column] = null;
            }
        }

        if ($lead->qualified_at !== null && ! $before($lead->qualified_at)) {
            $attrs['qualification_status'] = null;
        }

        if ($lead->assigned_at !== null && ! $before($lead->assigned_at)) {
            $attrs['assigned_to'] = null;
        }

        // Who had it: a person only once the hand-off had happened.
        // (A hand-off with no recorded time is taken as having happened.)
        $handedOff = $lead->automation_state === Lead::AUTOMATION_HANDED_OFF;
        $attrs['automation_state'] = match (true) {
            $handedOff && $lead->handed_off_at === null => Lead::AUTOMATION_HANDED_OFF,
            $before($lead->handed_off_at) => Lead::AUTOMATION_HANDED_OFF,
            $handedOff => null,
            default => $lead->automation_state,
        };

        return $copy->forceFill($attrs);
    }
}
