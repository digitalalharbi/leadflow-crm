<?php

namespace App\Domain\Tracking\Forms;

use App\Domain\Leads\InterestEngine;
use App\Models\Lead;
use App\Models\LeadFact;

/**
 * What a form's answers say about how serious the customer is — by the workspace's own rules.
 *
 * "Budget over two million, buying within three months, paying cash" is a stronger lead than any one of them, and only the workspace
 * knows what counts. Each rule is a condition and a number of points; the points of every rule that holds are added (up to a cap) and
 * kept as ONE fact on the lead, so the interest score can say why — "form answers: +20" — beside the replies and the brochure opens.
 */
class FormSignals
{
    public const CAP = 30;

    /**
     * @param  list<array<string,mixed>>  $rules  `settings.signals`
     * @param  array<string,mixed>  $answers  the validated answers
     * @return array{points:int, matched:list<array{id:string,label:string,points:int}>}
     */
    public function evaluate(array $rules, array $answers): array
    {
        $matched = [];

        foreach ($rules as $rule) {
            if (Logic::passes($rule['when'] ?? null, $answers)) {
                $matched[] = ['id' => (string) $rule['id'], 'label' => (string) $rule['label'], 'points' => (int) $rule['points']];
            }
        }

        return ['points' => min(self::CAP, array_sum(array_column($matched, 'points'))), 'matched' => $matched];
    }

    /** Scores the answers and keeps the result on the lead. Nothing to keep when no rule holds. */
    public function apply(Lead $lead, array $rules, array $answers): void
    {
        if ($rules === []) {
            return;
        }

        $result = $this->evaluate($rules, $answers);

        if ($result['points'] === 0) {
            return;
        }

        LeadFact::updateOrCreate(
            ['lead_id' => $lead->id, 'kind' => 'form_intent'],
            ['value' => 'form', 'detail' => $result, 'confidence' => 100, 'source' => 'landing_form', 'extracted_at' => now()],
        );

        InterestEngine::touch($lead->id, 'form_signals');
    }
}
