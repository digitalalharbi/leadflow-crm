<?php

namespace App\Domain\LeadSources;

use App\DataTransferObjects\IncomingLead;
use App\Models\LeadFormMapping;

/**
 * Applies a platform form's mapping to what just arrived: the project, and which answer is which. It runs inside the one
 * ingestion path, so a mapped lead is deduplicated, assigned, automated and timed like every other lead. No mapping, or a
 * lead that already knows its project, is left as it came.
 */
class LeadFormMappings
{
    /** Where an answer can go. Anything else is `custom:<key>`, the same place a form's unmapped answers go. */
    public const TARGETS = ['full_name', 'first_name', 'last_name', 'phone', 'email', 'city', 'budget_min', 'budget_max', 'purchase_purpose', 'payment_method', 'preferred_property_type', 'preferred_location', 'purchase_timeline'];

    public function apply(IncomingLead $incoming): IncomingLead
    {
        if (blank($incoming->formId) || ! in_array($incoming->provider->value, LeadFormMapping::PROVIDERS, true)) {
            return $incoming;
        }

        $mapping = LeadFormMapping::query()->where('provider', $incoming->provider->value)->where('form_ref', (string) $incoming->formId)->where('is_active', true)->first();

        if ($mapping === null) {
            return $incoming;
        }

        $answers = self::answers($incoming->rawPayload);
        $fields = [];
        $custom = [];

        foreach ((array) $mapping->field_mapping as $source => $target) {
            $value = $answers[mb_strtolower((string) $source)] ?? null;

            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            if (is_string($target) && str_starts_with($target, 'custom:')) {
                $custom[substr($target, 7)] = (string) $value;
            } elseif (in_array($target, self::TARGETS, true)) {
                $fields[$target] = trim((string) $value);
            }
        }

        return $incoming->withMapping($incoming->projectId ?? $mapping->project_id, $fields, $custom);
    }

    /**
     * A platform's answers by question, whichever way it sends them: Meta's `field_data` list, or flat keys.
     *
     * @return array<string,mixed>
     */
    public static function answers(array $raw): array
    {
        $out = [];

        foreach ((array) ($raw['field_data'] ?? []) as $row) {
            if (is_array($row) && isset($row['name'])) {
                $out[mb_strtolower((string) $row['name'])] = is_array($row['values'] ?? null) ? ($row['values'][0] ?? null) : ($row['values'] ?? null);
            }
        }

        foreach ($raw as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $out[mb_strtolower($key)] ??= $value;
            }
        }

        return $out;
    }
}
