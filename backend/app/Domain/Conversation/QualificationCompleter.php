<?php

namespace App\Domain\Conversation;

use App\Domain\Qualification\ProjectQualification;
use App\Enums\ActivityType;
use App\Models\Lead;
use App\Models\LeadAnswer;
use App\Models\LeadFact;
use App\Models\LeadMessage;
use App\Models\ProjectQuestion;
use App\Services\Leads\ActivityLogger;
use App\Services\Leads\LeadScoringService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Fills in the qualification the customer has already given, so nobody — not
 * the automation, not the rep — asks for it twice.
 *
 * Three sources, none of which overwrites an answer somebody already recorded:
 *  - what the conversation said (budget, timeline, property, appointment),
 *    written to the lead's own fields where they were blank;
 *  - a numbered reply to the qualification message, answered question by
 *    question in each question's own type;
 *  - what the lead already holds (from the form or an earlier answer), carried
 *    over to the project question that asks for it.
 */
class QualificationCompleter
{
    /** Question keys that ask for something the lead record can already hold. */
    private const KEYS = [
        'budget' => 'budget', 'budget_max' => 'budget', 'max_budget' => 'budget',
        'timeline' => 'timeline', 'purchase_timeline' => 'timeline', 'when' => 'timeline',
        'purpose' => 'purpose', 'purchase_purpose' => 'purpose',
        'property_type' => 'property', 'unit_type' => 'property', 'service' => 'property', 'type' => 'property',
        'appointment' => 'appointment', 'visit' => 'appointment', 'meeting' => 'appointment', 'viewing' => 'appointment',
        // The real estate questions.
        'transaction' => 'transaction', 'buy_or_rent' => 'transaction', 'deal_type' => 'transaction',
        'payment' => 'payment', 'payment_method' => 'payment', 'financing' => 'payment', 'cash_or_financing' => 'payment',
        'bedrooms' => 'bedrooms', 'rooms' => 'bedrooms', 'bedroom' => 'bedrooms',
        'district' => 'district', 'neighborhood' => 'district', 'neighbourhood' => 'district',
        'city' => 'city', 'location' => 'city',
        'reservation' => 'reservation', 'reserve' => 'reservation',
    ];

    private const TRANSACTION = ['buy' => 'شراء', 'rent' => 'إيجار'];

    private const PAYMENT = ['cash' => 'كاش', 'financing' => 'تمويل'];

    private const TIMELINE = ['immediate' => 'فورًا', 'within_1_month' => 'خلال شهر', 'within_3_months' => 'خلال 3 أشهر', 'within_6_months' => 'خلال 6 أشهر', 'later' => 'لاحقًا'];

    private const PURPOSE = ['residence' => 'سكن', 'investment' => 'استثمار'];

    private const PROPERTY = ['villa' => 'فيلا', 'apartment' => 'شقة', 'townhouse' => 'تاون هاوس', 'land' => 'أرض', 'commercial' => 'تجاري'];

    public function __construct(
        private ProjectQualification $qualification,
        private LeadScoringService $scoring,
        private ActivityLogger $activities,
        private QualificationReplyParser $parser,
    ) {}

    /**
     * What the conversation said, onto the lead's own blank fields. Returns the fields filled.
     *
     * @param  Collection<int,LeadFact>  $facts
     * @return list<string>
     */
    public function applyFacts(Lead $lead, Collection $facts): array
    {
        $fill = [];
        $budget = $facts->firstWhere('kind', Vocabulary::BUDGET);

        if ($budget?->value === 'stated' && $lead->budget_max === null && $lead->budget_min === null) {
            $fill['budget_min'] = $budget->detail['min'] ?? null;
            $fill['budget_max'] = $budget->detail['max'] ?? null;
        }

        $timeline = $facts->firstWhere('kind', Vocabulary::URGENCY)?->detail['timeline'] ?? null;
        if ($timeline && blank($lead->purchase_timeline)) {
            $fill['purchase_timeline'] = $timeline;
        }

        $service = $facts->firstWhere('kind', Vocabulary::SERVICE)?->value;
        if ($service && blank($lead->preferred_property_type)) {
            $fill['preferred_property_type'] = $service;
        }

        // The customer's latest word on a visit is the one that counts.
        if ($facts->firstWhere('kind', Vocabulary::APPOINTMENT)?->value === 'requested' && ! $lead->appointment_interest) {
            $fill['appointment_interest'] = true;
        }

        // What they want, in real estate terms — onto the blank fields, never over an answer.
        $said = fn (string $kind) => $facts->firstWhere('kind', $kind)?->value;

        if (($v = $said(Vocabulary::TRANSACTION)) && blank($lead->transaction_type)) {
            $fill['transaction_type'] = $v;
        }
        if (($v = $said(Vocabulary::PAYMENT)) && (blank($lead->payment_method) || $lead->payment_method === 'unknown')) {
            $fill['payment_method'] = $v;
        }
        if (($v = $said(Vocabulary::PURPOSE)) && (blank($lead->purchase_purpose) || $lead->purchase_purpose === 'unknown')) {
            $fill['purchase_purpose'] = $v;
        }
        if (($v = $said(Vocabulary::BEDROOMS)) && $lead->bedrooms === null) {
            $fill['bedrooms'] = (int) $v;
        }
        if (($v = $said(Vocabulary::DISTRICT)) && blank($lead->preferred_district)) {
            $fill['preferred_district'] = $v;
        }
        if ($said(Vocabulary::RESERVATION) === 'ready' && ! $lead->reservation_intent) {
            $fill['reservation_intent'] = true;
        }

        $fill = array_filter($fill, fn ($v) => $v !== null);

        if ($fill === []) {
            return [];
        }

        $lead->fill($fill)->save();

        // The score and the classification follow what was just learned, now — not at the next edit.
        $this->scoring->refresh($lead->refresh(), 'conversation');

        return array_keys($fill);
    }

    /** Carries what the lead already holds to the unanswered questions that ask for it. Returns how many. */
    public function syncAnswers(Lead $lead): int
    {
        $answers = [];

        foreach ($this->unanswered($lead) as $question) {
            $value = $this->known($lead, $question);

            if ($value !== null) {
                $answers[$question->id] = $value;
            }
        }

        return $this->record($lead, $answers, 'known');
    }

    /** A numbered reply to the qualification message, answered question by question. Returns how many. */
    public function fromReply(Lead $lead, LeadMessage $inbound): int
    {
        $asked = LeadMessage::withoutGlobalScopes()->where('lead_id', $lead->id)->where('direction', 'outbound')
            ->whereNotNull('context')->where('created_at', '<=', $inbound->created_at)
            ->where('created_at', '>=', $inbound->created_at->copy()->subDays(3))->latest('id')->first();

        $ids = (array) ($asked?->context['questions'] ?? []);

        if ($ids === [] || trim((string) $inbound->body) === '') {
            return 0;
        }

        $already = LeadAnswer::where('lead_id', $lead->id)->pluck('project_question_id')->all();
        $questions = ProjectQuestion::whereIn('id', $ids)->get()->sortBy(fn ($q) => array_search($q->id, $ids, true))->values();

        $answers = array_filter(
            $this->parser->parse((string) $inbound->body, $questions),
            fn ($value, $questionId) => ! in_array($questionId, $already, true),
            ARRAY_FILTER_USE_BOTH,
        );

        return $this->record($lead, $answers, 'reply');
    }

    /** @return Collection<int,ProjectQuestion> */
    public function unanswered(Lead $lead): Collection
    {
        $answered = LeadAnswer::where('lead_id', $lead->id)->pluck('project_question_id')->all();

        return $this->qualification->questionsFor($lead)->reject(fn ($q) => in_array($q->id, $answered, true))->values();
    }

    /** What the lead record already says for this question, in the question's own type; null when nothing. */
    private function known(Lead $lead, ProjectQuestion $question): mixed
    {
        $what = self::KEYS[mb_strtolower((string) $question->key)] ?? null;

        [$code, $label] = match ($what) {
            'budget' => [$lead->budget_max ?? $lead->budget_min, null],
            'timeline' => [$lead->purchase_timeline, self::TIMELINE[$lead->purchase_timeline] ?? null],
            'purpose' => [$lead->purchase_purpose && $lead->purchase_purpose !== 'unknown' ? $lead->purchase_purpose : null, self::PURPOSE[$lead->purchase_purpose] ?? null],
            'property' => [$lead->preferred_property_type, self::PROPERTY[$lead->preferred_property_type] ?? $lead->preferred_property_type],
            'appointment' => [$lead->appointment_interest ? true : null, null],
            'transaction' => [$lead->transaction_type, self::TRANSACTION[$lead->transaction_type] ?? null],
            'payment' => [$lead->payment_method && $lead->payment_method !== 'unknown' ? $lead->payment_method : null, self::PAYMENT[$lead->payment_method] ?? null],
            'bedrooms' => [$lead->bedrooms, null],
            'district' => [$lead->preferred_district, null],
            'city' => [$lead->preferred_location, null],
            'reservation' => [$lead->reservation_intent ? true : null, null],
            default => [null, null],
        };

        if ($code === null || $code === '') {
            return null;
        }

        return match ($question->type) {
            'number' => in_array($what, ['budget', 'bedrooms'], true) ? (int) $code : null,
            'boolean' => in_array($what, ['appointment', 'reservation'], true) ? true : null,
            'text' => $what === 'budget' ? number_format((float) $code).' ر.س' : ($label ?? (string) $code),
            'select' => $this->option($question, (string) $code, $label),
            default => null,
        };
    }

    private function option(ProjectQuestion $question, string $code, ?string $label): ?string
    {
        foreach ((array) $question->options as $option) {
            $value = (string) ($option['value'] ?? '');
            $text = Text::normalise((string) ($option['label'] ?? ''));

            if ($value === $code || ($label !== null && $text !== '' && str_contains($text, Text::normalise($label)))) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Writes the answers, re-reads the qualification and the score, and leaves
     * a line in the history saying where they came from. Returns how many.
     *
     * @param  array<int,mixed>  $answers  project_question_id => value
     */
    private function record(Lead $lead, array $answers, string $source): int
    {
        $clean = [];

        foreach ($answers as $questionId => $value) {
            $question = ProjectQuestion::find($questionId);

            try {
                $normalised = $question ? $this->qualification->normalize($question, $value) : null;
            } catch (InvalidArgumentException) {
                continue;
            }

            if ($normalised !== null) {
                $clean[$questionId] = $normalised;
            }
        }

        if ($clean === []) {
            return 0;
        }

        DB::transaction(function () use ($lead, $clean, $source) {
            foreach ($clean as $questionId => $value) {
                LeadAnswer::updateOrCreate(['lead_id' => $lead->id, 'project_question_id' => $questionId], ['value' => $value]);
            }

            $lead->qualification_status = $this->scoring->qualificationStatus($lead);
            $lead->save();
            $this->scoring->refresh($lead->refresh(), 'conversation');

            $this->activities->log($lead, ActivityType::QualificationUpdated, null, null, [
                'fields' => array_keys($clean), 'source' => 'conversation', 'via' => $source, 'by' => 'automation',
            ]);
        });

        return count($clean);
    }
}
