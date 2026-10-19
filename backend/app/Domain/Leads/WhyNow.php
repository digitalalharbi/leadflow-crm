<?php

namespace App\Domain\Leads;

use App\Models\Lead;
use App\Models\LeadEngagement;

/**
 * Why this lead, now — in what the customer did, not in what a rule decided:
 * replied, opened the brochure, opened or clicked an email, qualified. The
 * facts behind the next action, shown as a row of chips a rep reads in a
 * second, on the work screen and on the lead itself.
 */
class WhyNow
{
    /** @return list<string> */
    public static function signals(Lead $lead): array
    {
        $recent = LeadEngagement::where('lead_id', $lead->id)
            ->where('happened_at', '>=', now()->subDays(7))
            ->pluck('type')
            ->unique()
            ->all();

        return array_values(array_filter([
            $lead->first_response_at ? 'replied' : null,
            in_array(LeadEngagement::FILE_OPENED, $recent, true) ? 'brochure_opened' : null,
            in_array(LeadEngagement::LINK_CLICKED, $recent, true) ? 'email_clicked' : null,
            in_array(LeadEngagement::EMAIL_OPENED, $recent, true) ? 'email_opened' : null,
            $lead->qualification_status?->value === 'qualified' ? 'qualified' : null,
            $lead->is_priority ? 'priority' : null,
            $lead->needs_human_reason === 'no_reply' ? 'no_reply' : null,
        ]));
    }
}
