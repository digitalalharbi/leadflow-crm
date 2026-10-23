<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tracking\Forms\FieldPresets;
use App\Domain\Tracking\Forms\FormSchema;
use App\Http\Controllers\Controller;
use App\Models\LandingForm;
use App\Models\Project;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The landing pages a workspace designs, and the form on each.
 *
 * Reading is for everyone who can see the workspace; making or changing one is a manager's call, because a
 * form is a promise about what is asked of a customer and where each answer goes. A form that could not work —
 * a phone field that lands nowhere, a condition on a field that is not there, a form that cannot reach the
 * person who filled it — is refused with the field it is about, never saved half-made.
 */
class LandingFormController extends Controller
{
    public function __construct(private FormSchema $schema, private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', LandingForm::class);

        $f = $request->validate(['status' => ['nullable', Rule::in(LandingForm::STATUSES)], 'project_id' => ['nullable', 'uuid']]);

        $forms = LandingForm::query()->with('project:id,uuid,name')->withCount('links')
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['project_id'] ?? null, fn ($q, $uuid) => $q->whereIn('project_id', Project::where('uuid', $uuid)->select('id')))
            ->latest('id')->limit(200)->get();

        return ApiResponse::success(['forms' => $forms->map(fn (LandingForm $form) => $this->shape($form))->values()]);
    }

    public function presets()
    {
        $this->authorize('viewAny', LandingForm::class);

        return ApiResponse::success([
            'presets' => FieldPresets::all(),
            'types' => FormSchema::TYPES,
            'targets' => ['lead' => FormSchema::LEAD_TARGETS, 'tracking' => FormSchema::TRACKING_TARGETS],
        ]);
    }

    public function show(string $uuid)
    {
        $form = LandingForm::where('uuid', $uuid)->with('project:id,uuid,name')->withCount('links')->firstOrFail();
        $this->authorize('view', $form);

        return ApiResponse::success(['form' => $this->shape($form)]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', LandingForm::class);

        // A column default never reaches a model that was created without naming the column.
        $form = LandingForm::create($this->validated($request) + ['status' => 'draft', 'consent_required' => true, 'created_by' => $request->user()->id]);
        $this->audit->log('landing_form.created', $request->user(), $form, ['after' => ['name' => $form->name, 'status' => $form->status, 'fields' => count($form->fields)]]);

        return ApiResponse::created(['form' => $this->shape($form->load('project:id,uuid,name')->loadCount('links'))]);
    }

    public function update(Request $request, string $uuid)
    {
        $form = LandingForm::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $form);

        $before = ['name' => $form->name, 'status' => $form->status, 'fields' => count($form->fields ?? [])];
        $form->update($this->validated($request, $form, partial: true));
        $this->audit->log('landing_form.updated', $request->user(), $form, ['before' => $before, 'after' => ['name' => $form->name, 'status' => $form->status, 'fields' => count($form->fields)]]);

        return ApiResponse::success(['form' => $this->shape($form->refresh()->load('project:id,uuid,name')->loadCount('links'))]);
    }

    public function destroy(Request $request, string $uuid)
    {
        $form = LandingForm::where('uuid', $uuid)->firstOrFail();
        $this->authorize('delete', $form);

        $this->audit->log('landing_form.deleted', $request->user(), $form, ['before' => ['name' => $form->name]]);
        // Soft-deleted: the leads it brought keep their raw answers and their credit.
        $form->delete();

        return ApiResponse::success(null, 'Form deleted.');
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, ?LandingForm $current = null, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $https = ['nullable', 'string', 'max:500', 'regex:#^https://#i'];

        $data = $request->validate([
            'name' => [$req, 'string', 'max:160'],
            'project_id' => ['nullable', 'uuid'],
            'status' => ['sometimes', Rule::in(LandingForm::STATUSES)],
            'headline' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'benefits' => ['nullable', 'array', 'max:6'],
            'benefits.*' => ['string', 'max:120'],
            'cta_label' => ['nullable', 'string', 'max:60'],
            'logo_url' => $https,
            'image_url' => $https,
            'privacy_url' => $https,
            'thank_you_title' => ['nullable', 'string', 'max:160'],
            'thank_you_message' => ['nullable', 'string', 'max:1000'],
            'consent_required' => ['sometimes', 'boolean'],
            'consent_text' => ['nullable', 'string', 'max:600'],
            'fields' => [$req, 'array', 'min:1', 'max:'.FormSchema::MAX_FIELDS],
            'fields.*' => ['array'],
        ]);

        if (array_key_exists('project_id', $data)) {
            $data['project_id'] = blank($data['project_id']) ? null : Project::where('uuid', $data['project_id'])->value('id');
            abort_if($data['project_id'] === null && filled($request->input('project_id')), 404, 'Resource not found.');
        }

        if (array_key_exists('fields', $data)) {
            $errors = $this->schema->definitionErrors($data['fields']);

            if ($errors !== []) {
                throw ValidationException::withMessages(['fields' => collect($errors)->map(fn ($code, $key) => "{$key}: {$code}")->values()->all()]);
            }

            $data['fields'] = $this->schema->normalize($data['fields']);
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function shape(LandingForm $form): array
    {
        return [
            'id' => $form->uuid,
            'name' => $form->name,
            'status' => $form->status,
            'project' => $form->project ? ['id' => $form->project->uuid, 'name' => $form->project->name] : null,
            'headline' => $form->headline,
            'description' => $form->description,
            'benefits' => $form->benefits ?? [],
            'cta_label' => $form->cta_label,
            'logo_url' => $form->logo_url,
            'image_url' => $form->image_url,
            'thank_you_title' => $form->thank_you_title,
            'thank_you_message' => $form->thank_you_message,
            'consent_required' => (bool) $form->consent_required,
            'consent_text' => $form->consent_text,
            'privacy_url' => $form->privacy_url,
            'fields' => $form->fields ?? [],
            'steps' => $form->steps(),
            'links_count' => $form->links_count ?? null,
            'created_at' => $form->created_at?->toIso8601String(),
            'updated_at' => $form->updated_at?->toIso8601String(),
        ];
    }
}
