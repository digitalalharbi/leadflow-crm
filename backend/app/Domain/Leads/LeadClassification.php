<?php

namespace App\Domain\Leads;

use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\QualificationStatus;
use App\Models\Lead;

/**
 * The one word that says how seriously to take a lead.
 *
 * Status says where a lead is in the pipeline and temperature says how warm the
 * score is; a rep deciding who to call needs a single answer that combines them,
 * and a manager needs to count leads by it. So the answer is defined once, here,
 * in two forms that must agree — PHP for one lead (with the reason), SQL for a
 * whole tenant — and a test feeds both the same leads to keep them honest.
 *
 * Order matters: the first rule that matches wins.
 *
 *   unqualified  invalid data, or the project's own questions say it is the wrong fit
 *   qualified    reached the qualified stage or beyond, or qualification said yes
 *   priority     the project's own questions say so, or hot AND showing intent
 *                (asked for an appointment, or replied)
 *   hot / warm   the score's own temperature
 *   new          untouched: nobody has contacted them and the score is cold
 *   cold         the rest
 */
enum LeadClassification: string
{
    case New = 'new';
    case Cold = 'cold';
    case Warm = 'warm';
    case Hot = 'hot';
    case Priority = 'priority';
    case Qualified = 'qualified';
    case Unqualified = 'unqualified';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array{key: string, reason: string} reason is a translation key, never prose */
    public static function for(Lead $lead): array
    {
        $status = $lead->status;
        $temperature = $lead->temperature;

        // `qualification_status = unqualified` is every new lead's default — it
        // means "not asked yet", not "turned down" — so it cannot be the test.
        if ($status === LeadStatus::Invalid || $lead->wrong_fit === true) {
            return ['key' => self::Unqualified->value, 'reason' => $status === LeadStatus::Invalid ? 'invalid_data' : 'wrong_fit'];
        }

        if ($lead->qualification_status === QualificationStatus::Qualified
            || in_array($status, [LeadStatus::Qualified, LeadStatus::Appointment, LeadStatus::Negotiation, LeadStatus::Won], true)) {
            return ['key' => self::Qualified->value, 'reason' => 'meets_criteria'];
        }

        if ($lead->is_priority === true) {
            return ['key' => self::Priority->value, 'reason' => 'project_priority'];
        }

        if ($temperature === LeadTemperature::Hot && ($lead->appointment_interest === true || $lead->first_response_at !== null)) {
            return ['key' => self::Priority->value, 'reason' => $lead->appointment_interest === true ? 'hot_wants_appointment' : 'hot_replied'];
        }

        if ($temperature === LeadTemperature::Hot) {
            return ['key' => self::Hot->value, 'reason' => 'hot_score'];
        }

        if ($temperature === LeadTemperature::Warm) {
            return ['key' => self::Warm->value, 'reason' => 'warm_score'];
        }

        if ($status === LeadStatus::New && $lead->first_contact_at === null) {
            return ['key' => self::New->value, 'reason' => 'untouched'];
        }

        return ['key' => self::Cold->value, 'reason' => 'low_score'];
    }

    /** The same rules as a SQL CASE over the leads table, for grouping and filtering. */
    public static function sql(string $table = 'leads'): string
    {
        $t = $table;

        return sprintf(
            "case
                when %1\$s.status = '%2\$s' or %1\$s.wrong_fit = true then 'unqualified'
                when %1\$s.qualification_status = '%4\$s' or %1\$s.status in ('%5\$s', '%6\$s', '%7\$s', '%8\$s') then 'qualified'
                when %1\$s.is_priority = true then 'priority'
                when %1\$s.temperature = '%9\$s' and (%1\$s.appointment_interest = true or %1\$s.first_response_at is not null) then 'priority'
                when %1\$s.temperature = '%9\$s' then 'hot'
                when %1\$s.temperature = '%10\$s' then 'warm'
                when %1\$s.status = '%11\$s' and %1\$s.first_contact_at is null then 'new'
                else 'cold'
            end",
            $t,
            LeadStatus::Invalid->value,
            'unused',
            QualificationStatus::Qualified->value,
            LeadStatus::Qualified->value,
            LeadStatus::Appointment->value,
            LeadStatus::Negotiation->value,
            LeadStatus::Won->value,
            LeadTemperature::Hot->value,
            LeadTemperature::Warm->value,
            LeadStatus::New->value,
        );
    }
}
