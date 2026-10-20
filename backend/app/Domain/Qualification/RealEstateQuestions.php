<?php

namespace App\Domain\Qualification;

use App\Models\Project;
use App\Models\ProjectQuestion;

/**
 * The questions a real estate sale needs answered, as a project's own questions.
 *
 * They are ordinary project questions — the customer's answers land in the same place, the rules
 * score in the same way, the manager edits them in the same screen — whose keys the qualification
 * completer already understands, so a fact the customer volunteers ("فيلا في حي النرجس، كاش") fills
 * the matching question and it is never asked again. Seeding is idempotent: a question the project
 * already has (by key) is left exactly as the manager wrote it.
 */
class RealEstateQuestions
{
    /**
     * @return list<array<string,mixed>>
     */
    public static function set(): array
    {
        $opts = fn (array $pairs) => array_map(fn ($value, $label) => ['value' => $value, 'label' => $label], array_keys($pairs), array_values($pairs));

        return [
            ['key' => 'transaction', 'label' => 'شراء أم إيجار؟', 'type' => 'select', 'options' => $opts(['buy' => 'شراء', 'rent' => 'إيجار'])],
            ['key' => 'budget', 'label' => 'ما ميزانيتك التقريبية؟', 'type' => 'number'],
            ['key' => 'district', 'label' => 'في أي حي أو مدينة تفضّل؟', 'type' => 'text'],
            ['key' => 'property_type', 'label' => 'ما نوع العقار المطلوب؟', 'type' => 'select', 'options' => $opts(['villa' => 'فيلا', 'apartment' => 'شقة', 'townhouse' => 'تاون هاوس', 'land' => 'أرض', 'commercial' => 'تجاري'])],
            ['key' => 'bedrooms', 'label' => 'كم عدد الغرف؟', 'type' => 'number'],
            ['key' => 'payment', 'label' => 'كاش أم تمويل؟', 'type' => 'select', 'options' => $opts(['cash' => 'كاش', 'financing' => 'تمويل'])],
            ['key' => 'timeline', 'label' => 'متى تنوي الشراء؟', 'type' => 'select', 'options' => $opts(['within_month' => 'خلال شهر', 'within_3_months' => 'من شهر إلى 3 أشهر', 'within_6_months' => 'من 3 إلى 6 أشهر', 'over_6_months' => 'أكثر من 6 أشهر'])],
            ['key' => 'visit', 'label' => 'هل ترغب بزيارة الموقع؟', 'type' => 'boolean', 'rules' => [['op' => 'is_true', 'points' => 8]]],
            ['key' => 'reservation', 'label' => 'هل أنت جاهز للحجز؟', 'type' => 'boolean', 'rules' => [['op' => 'is_true', 'points' => 12, 'effect' => 'priority']]],
        ];
    }

    /**
     * Adds what the project does not already ask. Returns the questions created.
     *
     * @return list<ProjectQuestion>
     */
    public function seed(Project $project): array
    {
        $have = ProjectQuestion::where('project_id', $project->id)->pluck('key')->map(fn ($k) => mb_strtolower((string) $k))->all();
        $order = (int) ProjectQuestion::where('project_id', $project->id)->max('sort_order');
        $created = [];

        foreach (self::set() as $question) {
            if (in_array($question['key'], $have, true)) {
                continue;
            }

            $created[] = ProjectQuestion::create($question + [
                'project_id' => $project->id, 'options' => null, 'rules' => null,
                'is_required' => false, 'is_active' => true, 'sort_order' => ++$order,
            ]);
        }

        return $created;
    }
}
