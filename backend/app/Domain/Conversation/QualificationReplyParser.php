<?php

namespace App\Domain\Conversation;

use App\Models\ProjectQuestion;
use Illuminate\Support\Collection;

/**
 * "1. مليون ونصف 2. خلال شهرين" → the answer to question 1 and to question 2.
 *
 * The qualification message numbers the questions it asks; a customer who
 * answers in kind is answering them, and one who writes a single line to a
 * single question is too. Each answer is then read as the question's own type
 * — an amount for a number, a choice for a select, yes or no for a boolean.
 * What cannot be read as its type is left unanswered rather than guessed.
 */
final class QualificationReplyParser
{
    /**
     * @param  Collection<int,ProjectQuestion>  $asked  in the order they were asked
     * @return array<int,mixed> project_question_id => the answer, in the type's own form
     */
    public function parse(string $reply, Collection $asked): array
    {
        $asked = $asked->values();
        $text = strtr(trim($reply), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        if ($text === '' || $asked->isEmpty()) {
            return [];
        }

        $segments = $this->segments($text);

        // No numbering and one question: the whole reply is its answer.
        if ($segments === [] && $asked->count() === 1) {
            $segments = [1 => $text];
        }

        $answers = [];

        foreach ($segments as $number => $raw) {
            $question = $asked->get($number - 1);

            if (! $question) {
                continue;
            }

            $value = $this->coerce($question, $raw);

            if ($value !== null) {
                $answers[$question->id] = $value;
            }
        }

        return $answers;
    }

    /** @return array<int,string> number => text */
    private function segments(string $text): array
    {
        if (! preg_match_all('/(?:^|[\s\n])(\d{1,2})\s*[.)\-:،]\s*/u', $text, $marks, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [];
        }

        $out = [];

        foreach ($marks as $i => $mark) {
            $start = $mark[0][1] + strlen($mark[0][0]);
            $end = isset($marks[$i + 1]) ? $marks[$i + 1][0][1] : strlen($text);
            // Unicode-aware: a byte-wise trim list containing "،" would eat the first byte of an Arabic letter.
            $chunk = preg_replace('/^[\s.،,;؛]+|[\s.،,;؛]+$/u', '', substr($text, $start, $end - $start)) ?? '';

            if ($chunk !== '') {
                $out[(int) $mark[1][0]] = $chunk;
            }
        }

        return $out;
    }

    private function coerce(ProjectQuestion $question, string $raw): mixed
    {
        $norm = Text::normalise($raw);

        return match ($question->type) {
            'number' => ($a = AmountParser::parse($raw)) ? $a['max'] : (preg_match('/^\d+(?:[.,]\d+)?$/', trim($raw)) ? (float) str_replace(',', '.', trim($raw)) : null),
            'boolean' => $this->yesNo($norm),
            'select' => $this->choice($question, $norm),
            'text' => mb_substr(trim($raw), 0, 200),
            default => null,
        };
    }

    private function yesNo(string $norm): ?bool
    {
        if (preg_match('/^(?:نعم|ايوه|ايوا|اي|اكيد|yes|yep|sure|ok|تمام)\b/u', $norm)) {
            return true;
        }

        return preg_match('/^(?:لا|لأ|كلا|no|nope|not)\b/u', $norm) ? false : null;
    }

    private function choice(ProjectQuestion $question, string $norm): ?string
    {
        foreach ((array) $question->options as $option) {
            $label = Text::normalise((string) ($option['label'] ?? ''));
            $value = (string) ($option['value'] ?? '');

            if ($value !== '' && ($norm === Text::normalise($value) || ($label !== '' && (str_contains($norm, $label) || str_contains($label, $norm))))) {
                return $value;
            }
        }

        return null;
    }
}
