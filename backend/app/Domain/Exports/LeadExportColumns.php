<?php

namespace App\Domain\Exports;

use App\Domain\Leads\LeadTags;
use App\Models\Lead;

/** The columns a lead export carries, shared by the instant CSV and the background file so they never disagree. */
class LeadExportColumns
{
    /** @return array<int,string> */
    public static function headings(): array
    {
        return [
            'Name', 'Phone', 'Email', 'City', 'Project', 'Platform', 'Campaign',
            'Status', 'Temperature', 'Score', 'Assigned To', 'Next Follow-up', 'Created At', 'Tags',
        ];
    }

    /**
     * @param  array<int,string>  $hidden  what the person's role keeps from them (Permissions::hiddenData): a role
     *                                     without contacts gets the rows with the phone and email left empty,
     *                                     the same as on screen.
     * @return array<int,mixed>
     */
    public static function row(Lead $lead, array $hidden = []): array
    {
        $contacts = ! in_array('contacts', $hidden, true);

        return [
            $lead->full_name,
            $contacts ? ($lead->phone_normalized ?? $lead->phone) : null,
            $contacts ? $lead->email : null,
            $lead->city,
            $lead->project?->name,
            $lead->platform,
            $lead->campaign_name,
            $lead->status?->value,
            $lead->temperature?->value,
            (int) $lead->score,
            $lead->assignee?->name,
            $lead->next_follow_up_at?->toDateTimeString(),
            $lead->created_at?->toDateTimeString(),
            implode(', ', LeadTags::of($lead)),
        ];
    }
}
