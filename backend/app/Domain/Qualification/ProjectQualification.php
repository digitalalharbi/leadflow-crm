<?php

namespace App\Domain\Qualification;

use App\Models\Lead;
use App\Models\LeadAnswer;
use App\Models\ProjectQuestion;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * What a lead's answers to a project's own questions are worth.
 *
 * Deliberately the same kind of thing as the rest of scoring: every rule is a
 * sentence a rep can read — "budget above 2M: +12" — and the result says which
 * rule fired. An answer can do three things and no more: add or remove points,
 * mark the lead a priority, or say this is not a buyer for this project. None
 * of them closes a lead; the last one asks a person to look.
 */
class ProjectQualification
{
    public const OPS = [
        'select' => ['equals', 'in'],
        'boolean' => ['is_true', 'is_false'],
        'number' => ['gte', 'lte', 'between'],
        'text' => ['filled'],
    ];

    public const EFFECTS = ['priority', 'wrong_fit'];

    /** The most the answers can move a score either way. */
    public const CAP = 30;

    /**
     * @return array{points:int, reasons:array<int,array{key:string,label:string,points:int}>, priority:bool, wrong_fit:bool, missing:int}
     */
    public function evaluate(Lead $lead): array
    {
        $questions = $this->questionsFor($lead);

        if ($questions->isEmpty()) {
            return ['points' => 0, 'reasons' => [], 'priority' => false, 'wrong_fit' => false, 'missing' => 0];
        }

        $answers = LeadAnswer::where('lead_id', $lead->id)->get()->keyBy('project_question_id');

        $points = 0;
        $reasons = [];
        $priority = false;
        $wrongFit = false;
        $missing = 0;

        foreach ($questions as $question) {
            $value = $answers[$question->id]->value ?? null;

            if ($this->isBlank($value)) {
                $question->is_required && $missing++;

                continue;
            }

            foreach ((array) $question->rules as $rule) {
                if (! $this->matches($question, $rule, $value)) {
                    continue;
                }

                $worth = (int) ($rule['points'] ?? 0);
                $points += $worth;

                match ($rule['effect'] ?? null) {
                    'priority' => $priority = true,
                    'wrong_fit' => $wrongFit = true,
                    default => null,
                };

                if ($worth !== 0 || ($rule['effect'] ?? null)) {
                    $reasons[] = [
                        'key' => 'q_'.$question->key,
                        'label' => $question->label.': '.$this->describe($question, $value),
                        'points' => $worth,
                    ];
                }

                // One rule per question: the first that matches. Two rules that
                // both fire on one answer is a score nobody can explain.
                break;
            }
        }

        return [
            'points' => max(-self::CAP, min(self::CAP, $points)),
            'reasons' => $reasons,
            'priority' => $priority,
            'wrong_fit' => $wrongFit,
            'missing' => $missing,
        ];
    }

    /** @return Collection<int,ProjectQuestion> */
    public function questionsFor(Lead $lead)
    {
        if ($lead->project_id === null) {
            return collect();
        }

        return ProjectQuestion::where('project_id', $lead->project_id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get();
    }

    /**
     * Checks an answer against its question and returns what to store.
     *
     * @throws InvalidArgumentException
     */
    public function normalize(ProjectQuestion $question, mixed $raw): mixed
    {
        if ($this->isBlank($raw)) {
            return null;
        }

        return match ($question->type) {
            'select' => $this->select($question, $raw),
            'boolean' => $this->boolean($raw),
            'number' => is_numeric($raw) ? $raw + 0 : throw new InvalidArgumentException('A number is expected.'),
            'text' => mb_substr(trim((string) $raw), 0, 1000),
            default => throw new InvalidArgumentException('Unknown question type.'),
        };
    }

    private function select(ProjectQuestion $question, mixed $raw): string
    {
        $values = array_column((array) $question->options, 'value');

        if (! is_string($raw) || ! in_array($raw, $values, true)) {
            throw new InvalidArgumentException('That is not one of the choices.');
        }

        return $raw;
    }

    private function boolean(mixed $raw): bool
    {
        $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? throw new InvalidArgumentException('Yes or no is expected.');
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /** @param array<string,mixed> $rule */
    private function matches(ProjectQuestion $question, array $rule, mixed $value): bool
    {
        $target = $rule['value'] ?? null;

        return match ($rule['op'] ?? null) {
            'equals' => $value === $target,
            'in' => is_array($target) && in_array($value, $target, true),
            'is_true' => $value === true,
            'is_false' => $value === false,
            'gte' => is_numeric($value) && is_numeric($target) && $value >= $target,
            'lte' => is_numeric($value) && is_numeric($target) && $value <= $target,
            'between' => is_numeric($value) && is_array($target) && count($target) === 2
                && $value >= $target[0] && $value <= $target[1],
            'filled' => true,
            default => false,
        };
    }

    private function describe(ProjectQuestion $question, mixed $value): string
    {
        return match ($question->type) {
            'select' => collect((array) $question->options)->firstWhere('value', $value)['label'] ?? (string) $value,
            'boolean' => $value ? 'نعم' : 'لا',
            default => (string) $value,
        };
    }
}
