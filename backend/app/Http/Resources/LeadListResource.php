<?php

namespace App\Http\Resources;

use App\Domain\Leads\NextBestAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Slim payload for the leads table — keeps the list endpoint fast. */
class LeadListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'whatsapp' => $this->phone_normalized ? ltrim($this->phone_normalized, '+') : null,
            /*
             * Which language to open the call in, read off the number's country
             * code. It belongs in the slim payload because the moment it is
             * useful is before dialling, in the work queue — knowing afterwards
             * that the customer does not speak Arabic is knowing too late.
             */
            'language' => $this->resource->language(),
            'email' => $this->email,
            'city' => $this->city,
            'status' => $this->status?->value,
            'temperature' => $this->temperature?->value,
            // Hot, and the one to call first. Not a fourth temperature.
            'is_priority' => (bool) $this->is_priority,
            // The project's own questions say this is not a buyer for it.
            'wrong_fit' => (bool) $this->wrong_fit,
            'score' => $this->score,
            'platform' => $this->platform,
            'campaign_name' => $this->campaign_name,
            'project' => $this->whenLoaded('project', fn () => $this->project ? [
                'id' => $this->project->uuid,
                'name' => $this->project->name,
            ] : null),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => $this->assignee->uuid,
                'name' => $this->assignee->name,
            ] : null),
            /*
             * What to do with this lead, in a word and how urgently — so a list
             * says "call now" beside the name instead of leaving a rep to work
             * it out from a stage and a date. Only where the list asked for it.
             */
            'next_action' => $this->when($request->attributes->get('with_next_action'), fn () => $this->nextAction($request)),
            'next_follow_up_at' => $this->next_follow_up_at?->toIso8601String(),
            'is_overdue' => $this->next_follow_up_at !== null
                && $this->next_follow_up_at->isPast()
                && ! $this->status?->isClosed(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** @return array{action:string,urgency:string,reason:string} */
    private function nextAction(Request $request): array
    {
        // One tenant, one target: read once per request rather than per row.
        $target = $request->attributes->get('first_contact_target')
            ?? tap((int) ($request->user()?->tenant?->settings?->first_contact_target_minutes ?? 30),
                fn ($minutes) => $request->attributes->set('first_contact_target', $minutes));

        $result = app(NextBestAction::class)->for($this->resource, $target);

        return ['action' => $result['action'], 'urgency' => $result['urgency'], 'reason' => $result['reason']];
    }
}
