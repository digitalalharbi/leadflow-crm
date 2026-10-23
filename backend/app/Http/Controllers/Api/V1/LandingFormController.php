<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tracking\Forms\FieldPresets;
use App\Domain\Tracking\Forms\FormDefinition;
use App\Domain\Tracking\Forms\FormDesign;
use App\Domain\Tracking\Forms\FormLayout;
use App\Domain\Tracking\Forms\FormPublisher;
use App\Domain\Tracking\Forms\FormSchema;
use App\Domain\Tracking\Forms\FormTemplates;
use App\Domain\Tracking\Forms\PhoneCountries;
use App\Http\Controllers\Controller;
use App\Models\LandingForm;
use App\Models\Project;
use App\Models\TrackingEvent;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The landing pages a workspace designs, and the form on each.
 *
 * Reading is for everyone who can see the workspace; making or changing one is a manager's call, because a
 * form is a promise about what is asked of a customer and where each answer goes. A form that could not work —
 * a phone field that lands nowhere, a condition on a field that is not there, a form that cannot reach the
 * person who filled it — is refused with the field it is about, never saved half-made.
 *
 * What a manager edits is a DRAFT. Publishing freezes it as a version; the public page serves the version, so changing the draft never
 * reaches a campaign that is already running.
 */
class LandingFormController extends Controller
{
    public function __construct(private FormSchema $schema, private FormPublisher $publisher, private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', LandingForm::class);

        $f = $request->validate(['status' => ['nullable', Rule::in(LandingForm::STATUSES)], 'project_id' => ['nullable', 'uuid']]);

        $forms = LandingForm::query()->with(['project:id,uuid,name', 'publishedVersion'])->withCount('links')
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
            'countries' => PhoneCountries::DIAL,
            'design' => ['header_layouts' => FormDesign::HEADER_LAYOUTS, 'palette' => FormDesign::PALETTE, 'defaults' => FormDesign::defaults(), 'settings' => FormDesign::settingsDefaults()],
        ]);
    }

    public function templates()
    {
        $this->authorize('viewAny', LandingForm::class);

        return ApiResponse::success(['templates' => collect(FormTemplates::all())->map(fn ($t, $key) => [
            'key' => $key, 'name' => $t['name'], 'description' => $t['description'],
            'fields' => count(array_filter($t['definition']['fields'], fn ($f) => empty($f['hidden']) && ! in_array($f['type'], FormSchema::DISPLAY, true))),
            'steps' => ($t['definition']['settings']['mode'] ?? 'single') === 'multi' ? count($t['definition']['layout']['sections']) : 1,
        ])->values()]);
    }

    public function show(string $uuid)
    {
        $form = LandingForm::where('uuid', $uuid)->with(['project:id,uuid,name', 'publishedVersion'])->withCount('links')->firstOrFail();
        $this->authorize('view', $form);

        return ApiResponse::success(['form' => $this->shape($form)]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', LandingForm::class);

        $template = $request->input('template');
        $base = $template === null ? [] : (FormTemplates::get((string) $template) ?? throw ValidationException::withMessages(['template' => ['unknown']]));

        // A column default never reaches a model that was created without naming the column.
        $form = LandingForm::create($this->validated($request, base: $base) + ['status' => 'draft', 'consent_required' => $base['consent_required'] ?? true, 'created_by' => $request->user()->id]);
        $this->audit->log('landing_form.created', $request->user(), $form, ['after' => ['name' => $form->name, 'status' => $form->status, 'fields' => count($form->fields), 'template' => $template]]);

        return ApiResponse::created(['form' => $this->shape($form->load(['project:id,uuid,name', 'publishedVersion'])->loadCount('links'))]);
    }

    public function update(Request $request, string $uuid)
    {
        $form = LandingForm::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $form);

        $before = ['name' => $form->name, 'status' => $form->status, 'fields' => count($form->fields ?? [])];
        $data = $this->validated($request, $form, partial: true);
        $status = $data['status'] ?? null;
        unset($data['status']);

        $form->update($data);

        if ($status === 'published') {
            $this->publishOrRefuse($form, $request);
        } elseif ($status !== null) {
            $form->update(['status' => $status]);
        }

        $this->audit->log('landing_form.updated', $request->user(), $form, ['before' => $before, 'after' => ['name' => $form->name, 'status' => $form->status, 'fields' => count($form->fields)]]);

        return ApiResponse::success(['form' => $this->shape($form->refresh()->load(['project:id,uuid,name', 'publishedVersion'])->loadCount('links'))]);
    }

    public function publish(Request $request, string $uuid)
    {
        $form = LandingForm::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $form);

        $this->publishOrRefuse($form, $request);

        return ApiResponse::success(['form' => $this->shape($form->refresh()->load(['project:id,uuid,name', 'publishedVersion'])->loadCount('links'))]);
    }

    public function versions(string $uuid)
    {
        $form = LandingForm::where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $form);

        $versions = $form->versions()->with('publisher:id,name')->orderByDesc('version')->limit(50)->get();
        $submits = TrackingEvent::query()->whereIn('form_version_id', $versions->pluck('id'))->where('event_type', 'form_submit')
            ->selectRaw('form_version_id, count(*) as n')->groupBy('form_version_id')->pluck('n', 'form_version_id');

        return ApiResponse::success(['versions' => $versions->map(fn ($v) => [
            'id' => $v->uuid, 'version' => $v->version, 'variant' => $v->variant, 'published_at' => $v->published_at->toIso8601String(),
            'published_by' => $v->publisher?->name, 'is_live' => $v->id === $form->published_version_id, 'submissions' => (int) ($submits[$v->id] ?? 0),
        ])->values()]);
    }

    public function duplicate(Request $request, string $uuid)
    {
        $form = LandingForm::where('uuid', $uuid)->firstOrFail();
        $this->authorize('create', LandingForm::class);
        $this->authorize('view', $form);

        $copy = $form->replicate(['uuid', 'status', 'published_version_id', 'published_at', 'created_by']);
        $copy->fill(['name' => mb_substr($form->name, 0, 140).' (نسخة)', 'status' => 'draft', 'created_by' => $request->user()->id])->save();

        $this->audit->log('landing_form.duplicated', $request->user(), $copy, ['after' => ['from' => $form->uuid, 'name' => $copy->name]]);

        return ApiResponse::created(['form' => $this->shape($copy->load(['project:id,uuid,name', 'publishedVersion'])->loadCount('links'))]);
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

    private function publishOrRefuse(LandingForm $form, Request $request): void
    {
        $problems = $this->publisher->problems($form);

        if ($problems !== []) {
            throw ValidationException::withMessages(collect($problems)->map(fn ($code, $path) => ["{$path}: {$code}"])->all());
        }

        $version = $this->publisher->publish($form, $request->user());
        $this->audit->log('landing_form.published', $request->user(), $form, ['after' => ['version' => $version->version, 'checksum' => $version->checksum]]);
    }

    /**
     * @param  array<string,mixed>  $base  a template's definition, under whatever the request gives
     * @return array<string,mixed>
     */
    private function validated(Request $request, ?LandingForm $current = null, bool $partial = false, array $base = []): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $fieldsRule = $base !== [] ? ['sometimes', 'array', 'min:1', 'max:'.FormSchema::MAX_FIELDS] : [$req, 'array', 'min:1', 'max:'.FormSchema::MAX_FIELDS];

        $data = $request->validate([
            'name' => [$req, 'string', 'max:160'],
            'template' => ['sometimes', 'nullable', 'string', 'max:40'],
            'project_id' => ['nullable', 'uuid'],
            'status' => ['sometimes', Rule::in(LandingForm::STATUSES)],
            'headline' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'benefits' => ['nullable', 'array', 'max:6'],
            'benefits.*' => ['string', 'max:120'],
            'cta_label' => ['nullable', 'string', 'max:60'],
            'logo_url' => ['nullable', 'string', 'max:500'],
            'image_url' => ['nullable', 'string', 'max:500'],
            'privacy_url' => ['nullable', 'string', 'max:500', 'regex:#^https://#i'],
            'thank_you_title' => ['nullable', 'string', 'max:160'],
            'thank_you_message' => ['nullable', 'string', 'max:1000'],
            'consent_required' => ['sometimes', 'boolean'],
            'consent_text' => ['nullable', 'string', 'max:600'],
            'fields' => $fieldsRule,
            'fields.*' => ['array'],
            'layout' => ['sometimes', 'nullable', 'array'],
            'design' => ['sometimes', 'nullable', 'array'],
            'settings' => ['sometimes', 'nullable', 'array'],
        ]);

        unset($data['template']);

        // Plain text only: what a manager types is shown to strangers, so a tag is never kept.
        foreach (['name', 'headline', 'description', 'cta_label', 'thank_you_title', 'thank_you_message', 'consent_text'] as $text) {
            if (isset($data[$text])) {
                $data[$text] = trim(strip_tags($data[$text]));
            }
        }

        if (isset($data['benefits'])) {
            $data['benefits'] = array_values(array_filter(array_map(fn ($b) => trim(strip_tags((string) $b)), $data['benefits']), fn ($b) => $b !== ''));
        }

        // The template under, the request over.
        $merged = $data + array_intersect_key($base, array_flip(['headline', 'description', 'benefits', 'cta_label', 'thank_you_title', 'thank_you_message', 'consent_required', 'consent_text', 'fields', 'layout', 'design', 'settings']));

        foreach (['logo_url', 'image_url'] as $image) {
            if (array_key_exists($image, $merged) && ! FormDesign::imageOk($merged[$image])) {
                throw ValidationException::withMessages([$image => ['invalid_image']]);
            }

            if (filled($merged[$image] ?? null) && str_starts_with((string) $merged[$image], '/api/') && ! Storage::disk('local')->exists('form-assets/'.basename((string) $merged[$image]))) {
                throw ValidationException::withMessages([$image => ['invalid_image']]);
            }
        }

        if (array_key_exists('project_id', $merged)) {
            $merged['project_id'] = blank($merged['project_id']) ? null : Project::where('uuid', $merged['project_id'])->value('id');
            abort_if($merged['project_id'] === null && filled($request->input('project_id')), 404, 'Resource not found.');
        }

        $fieldsGiven = array_key_exists('fields', $merged);
        $layoutGiven = array_key_exists('layout', $merged);

        if ($fieldsGiven) {
            $errors = $this->schema->definitionErrors($merged['fields'], $merged['layout'] ?? $current?->layout);

            if ($errors !== []) {
                throw ValidationException::withMessages(['fields' => collect($errors)->map(fn ($code, $key) => "{$key}: {$code}")->values()->all()]);
            }

            $merged['fields'] = $this->schema->normalize($merged['fields']);
        }

        if ($fieldsGiven || $layoutGiven) {
            $fields = $merged['fields'] ?? $this->schema->normalize($current?->fields ?? []);
            $merged['layout'] = (new FormLayout)->normalize($layoutGiven ? $merged['layout'] : $current?->layout, $fields);
        }

        if (array_key_exists('design', $merged) || array_key_exists('settings', $merged)) {
            $keys = array_column($merged['fields'] ?? $this->schema->normalize($current?->fields ?? []), 'key');
            $problems = FormDesign::errors((array) ($merged['design'] ?? $current?->design ?? []), (array) ($merged['settings'] ?? $current?->settings ?? []), $keys);

            if ($problems !== []) {
                throw ValidationException::withMessages(collect($problems)->map(fn ($code, $path) => [$code])->all());
            }

            if (array_key_exists('design', $merged)) {
                $merged['design'] = FormDesign::normalizeDesign((array) $merged['design']);
            }

            if (array_key_exists('settings', $merged)) {
                $merged['settings'] = FormDesign::normalizeSettings((array) $merged['settings'], max(1, (int) collect($merged['fields'] ?? $current?->fields ?? [])->max('step')));
            }
        }

        return $merged;
    }

    /** @return array<string,mixed> */
    private function shape(LandingForm $form): array
    {
        $snapshot = FormDefinition::snapshot($form);
        $live = $form->published_version_id ? $form->publishedVersion : null;

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
            'fields' => $snapshot['fields'],
            'layout' => $snapshot['layout'],
            'design' => $snapshot['design'],
            'settings' => $snapshot['settings'],
            'steps' => FormDefinition::steps($snapshot),
            'published_version' => $live ? ['id' => $live->uuid, 'version' => $live->version, 'published_at' => $live->published_at->toIso8601String()] : null,
            'has_unpublished_changes' => $live !== null && $live->checksum !== FormDefinition::checksum($snapshot),
            'links_count' => $form->links_count ?? null,
            'created_at' => $form->created_at?->toIso8601String(),
            'updated_at' => $form->updated_at?->toIso8601String(),
        ];
    }
}
