<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Qualification\ProjectQualification;
use App\Domain\Qualification\RealEstateQuestions;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What a project asks, and what each answer is worth.
 *
 * A manager's tool, because an answer's rules change who gets called first:
 * the same authority as editing the project itself. Rules are validated hard
 * here — an operator that does not fit the question's type, or a choice that
 * is not on the list, is a rule that silently never fires, and a question whose
 * rules never fire looks fine on the screen for months.
 */
class ProjectQuestionController extends Controller
{
    public function index(string $projectUuid)
    {
        $project = Project::where('uuid', $projectUuid)->firstOrFail();
        $this->authorize('view', $project);

        return ApiResponse::success([
            'questions' => ProjectQuestion::where('project_id', $project->id)
                ->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (ProjectQuestion $q) => $this->present($q))->values(),
            'types' => ProjectQuestion::TYPES,
            'operators' => ProjectQualification::OPS,
            'effects' => ProjectQualification::EFFECTS,
            'cap' => ProjectQualification::CAP,
        ]);
    }

    public function store(Request $request, string $projectUuid)
    {
        $project = Project::where('uuid', $projectUuid)->firstOrFail();
        $this->authorize('update', $project);

        $data = $this->validated($request);

        $question = ProjectQuestion::create($data + [
            'project_id' => $project->id,
            'key' => $this->uniqueKey($project, $data['label']),
            'sort_order' => (int) ProjectQuestion::where('project_id', $project->id)->max('sort_order') + 1,
            'is_active' => true,
        ]);

        return ApiResponse::created(['question' => $this->present($question)]);
    }

    /** The real estate set — transaction, budget, district, type, rooms, payment, timeline, visit, reservation — added where missing. */
    public function seedRealEstate(string $projectUuid, RealEstateQuestions $set)
    {
        $project = Project::where('uuid', $projectUuid)->firstOrFail();
        $this->authorize('update', $project);

        $created = $set->seed($project);

        return ApiResponse::success([
            'created' => count($created),
            'questions' => ProjectQuestion::where('project_id', $project->id)->orderBy('sort_order')->orderBy('id')->get()->map(fn (ProjectQuestion $q) => $this->present($q))->values(),
        ]);
    }

    public function update(Request $request, string $uuid)
    {
        $question = ProjectQuestion::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $question->project);

        // The type is not editable: answers already given were given to a
        // question of that type, and turning a yes/no into a number reinterprets
        // them all. Make a new question instead.
        $data = $this->validated($request, $question);

        $question->update($data);

        return ApiResponse::success(['question' => $this->present($question->refresh())]);
    }

    public function destroy(string $uuid)
    {
        $question = ProjectQuestion::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $question->project);

        $question->delete();

        return ApiResponse::success(null, 'Question deleted.');
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, ?ProjectQuestion $existing = null): array
    {
        $partial = $existing !== null;
        $type = $existing?->type ?? $request->input('type');

        $data = $request->validate([
            'label' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'type' => [$partial ? 'prohibited' : 'required', Rule::in(ProjectQuestion::TYPES)],
            'options' => ['sometimes', 'nullable', 'array', 'min:2', 'max:12'],
            'options.*.value' => ['required_with:options', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'options.*.label' => ['required_with:options', 'string', 'max:120'],
            'rules' => ['sometimes', 'nullable', 'array', 'max:12'],
            'rules.*.op' => ['required', 'string'],
            'rules.*.value' => ['nullable'],
            'rules.*.points' => ['sometimes', 'integer', 'between:-'.ProjectQualification::CAP.','.ProjectQualification::CAP],
            'rules.*.effect' => ['sometimes', 'nullable', Rule::in(ProjectQualification::EFFECTS)],
            'is_required' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ]);

        if ($type === 'select') {
            $options = $data['options'] ?? $existing?->options;

            if (blank($options)) {
                throw ValidationException::withMessages(['options' => 'A choice question needs at least two choices.']);
            }

            if (count(array_unique(array_column($options, 'value'))) !== count($options)) {
                throw ValidationException::withMessages(['options' => 'Each choice needs its own value.']);
            }
        } else {
            unset($data['options']);
        }

        foreach ($data['rules'] ?? [] as $index => $rule) {
            $this->checkRule($type, $rule, $data['options'] ?? $existing?->options ?? [], $index);
        }

        return $data;
    }

    /** @param array<string,mixed> $rule */
    private function checkRule(?string $type, array $rule, array $options, int $index): void
    {
        $fail = fn (string $why) => throw ValidationException::withMessages(["rules.$index" => $why]);

        $allowed = ProjectQualification::OPS[$type] ?? [];

        if (! in_array($rule['op'], $allowed, true)) {
            $fail("'{$rule['op']}' does not fit a {$type} question.");
        }

        $value = $rule['value'] ?? null;
        $choices = array_column($options, 'value');

        match ($rule['op']) {
            'equals' => in_array($value, $choices, true) || $fail('That is not one of the choices.'),
            'in' => (is_array($value) && $value !== [] && array_diff($value, $choices) === []) || $fail('Every value must be one of the choices.'),
            'gte', 'lte' => is_numeric($value) || $fail('A number is expected.'),
            'between' => (is_array($value) && count($value) === 2 && is_numeric($value[0]) && is_numeric($value[1]) && $value[0] <= $value[1]) || $fail('Two numbers, smallest first.'),
            default => null,
        };
    }

    private function uniqueKey(Project $project, string $label): string
    {
        $base = Str::slug(Str::ascii($label, 'en'), '_') ?: 'q';
        $base = mb_substr($base, 0, 40);
        $key = $base;
        $n = 2;

        while (ProjectQuestion::where('project_id', $project->id)->where('key', $key)->exists()) {
            $key = $base.'_'.$n++;
        }

        return $key;
    }

    /** @return array<string,mixed> */
    private function present(ProjectQuestion $q): array
    {
        return [
            'id' => $q->uuid,
            'key' => $q->key,
            'label' => $q->label,
            'type' => $q->type,
            'options' => $q->options ?? [],
            'rules' => $q->rules ?? [],
            'is_required' => $q->is_required,
            'is_active' => $q->is_active,
            'sort_order' => $q->sort_order,
        ];
    }
}
