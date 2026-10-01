<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BrandingResource;
use App\Models\CustomField;
use App\Models\LostReason;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function show(Request $request)
    {
        $tenant = $request->user()->tenant->load('branding', 'settings');
        $this->authorize('view', $tenant);

        return ApiResponse::success([
            'branding' => new BrandingResource($tenant),
            'settings' => [
                'locale' => $tenant->settings?->locale,
                'currency' => $tenant->settings?->currency,
                'timezone' => $tenant->settings?->timezone,
                'default_country_code' => $tenant->settings?->default_country_code,
                'assignment_mode' => $tenant->settings?->assignment_mode,
                'score_warm_threshold' => $tenant->settings?->score_warm_threshold,
                'score_hot_threshold' => $tenant->settings?->score_hot_threshold,
                'first_contact_target_minutes' => $tenant->settings?->first_contact_target_minutes,
                'whatsapp_template' => $tenant->settings?->whatsapp_template,
                'onboarding' => $tenant->settings?->onboarding,
            ],
            'subscription' => [
                'plan' => $tenant->plan,
                'status' => $tenant->subscription_status,
                'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
                'subscription_ends_at' => $tenant->subscription_ends_at?->toIso8601String(),
            ],
            'lost_reasons' => LostReason::orderBy('sort_order')->get()
                ->map(fn ($r) => ['id' => $r->uuid, 'label' => $r->label, 'is_active' => $r->is_active]),
            'custom_fields' => CustomField::orderBy('sort_order')->get()
                ->map(fn ($f) => [
                    'id' => $f->uuid, 'key' => $f->key, 'label' => $f->label,
                    'type' => $f->type, 'options' => $f->options,
                    'is_required' => $f->is_required, 'is_active' => $f->is_active,
                ]),
        ]);
    }

    public function updateBranding(Request $request)
    {
        $tenant = $request->user()->tenant;
        $this->authorize('update', $tenant);

        $data = $request->validate([
            'company_name' => ['sometimes', 'string', 'max:255'],
            'system_name' => ['sometimes', 'string', 'max:255'],
            'logo_url' => ['nullable', 'url', 'max:2048'],
            'login_logo_url' => ['nullable', 'url', 'max:2048'],
            'login_background_url' => ['nullable', 'url', 'max:2048'],
            'favicon_url' => ['nullable', 'url', 'max:2048'],
            'primary_color' => ['sometimes', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['sometimes', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:32'],
        ]);

        $tenant->branding->update($data);
        $this->audit->log('tenant.branding_updated', $request->user(), $tenant, ['fields' => array_keys($data)]);

        return ApiResponse::success(['branding' => new BrandingResource($tenant->fresh(['branding', 'settings']))]);
    }

    public function updateSettings(Request $request)
    {
        $tenant = $request->user()->tenant;
        $this->authorize('update', $tenant);

        $data = $request->validate([
            'locale' => ['sometimes', 'in:ar,en'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'timezone' => ['sometimes', 'timezone'],
            'default_country_code' => ['sometimes', 'string', 'max:5'],
            'assignment_mode' => ['sometimes', 'in:manual,round_robin'],
            'score_warm_threshold' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'score_hot_threshold' => ['sometimes', 'integer', 'min:2', 'max:100', 'gt:score_warm_threshold'],
            // How long a new lead may sit untouched before the dashboard calls
            // it neglected. A week is the ceiling; past that it is not a target.
            'first_contact_target_minutes' => ['sometimes', 'integer', 'min:1', 'max:10080'],
            'whatsapp_template' => ['nullable', 'string', 'max:1000'],
        ]);

        $tenant->settings->update($data);
        $this->audit->log('tenant.settings_updated', $request->user(), $tenant, ['fields' => array_keys($data)]);

        return ApiResponse::success(['settings' => $tenant->settings->fresh()]);
    }

    /** Marks an onboarding checklist item done; the UI hides the card when all are. */
    public function completeOnboardingStep(Request $request)
    {
        $tenant = $request->user()->tenant;
        $this->authorize('update', $tenant);

        $data = $request->validate([
            'step' => ['required', 'in:branding,team,project,lead_source,first_lead'],
        ]);

        $onboarding = $tenant->settings->onboarding ?? [];
        $onboarding[$data['step']] = true;
        $tenant->settings->update(['onboarding' => $onboarding]);

        return ApiResponse::success(['onboarding' => $onboarding]);
    }

    public function storeLostReason(Request $request)
    {
        $this->authorize('update', $request->user()->tenant);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
        ]);

        $reason = LostReason::create([
            'label' => $data['label'],
            'sort_order' => (int) LostReason::max('sort_order') + 1,
        ]);

        return ApiResponse::created(['lost_reason' => ['id' => $reason->uuid, 'label' => $reason->label, 'is_active' => true]]);
    }

    public function updateLostReason(Request $request, string $uuid)
    {
        $this->authorize('update', $request->user()->tenant);

        $reason = LostReason::where('uuid', $uuid)->firstOrFail();

        $reason->update($request->validate([
            'label' => ['sometimes', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]));

        return ApiResponse::success(['lost_reason' => ['id' => $reason->uuid, 'label' => $reason->label, 'is_active' => $reason->is_active]]);
    }

    public function storeCustomField(Request $request)
    {
        $this->authorize('update', $request->user()->tenant);

        $data = $request->validate([
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('custom_fields', 'key')->where('tenant_id', $request->user()->tenant_id)],
            'label' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(CustomField::TYPES)],
            'options' => ['nullable', 'array', 'required_if:type,select'],
            'options.*' => ['string', 'max:120'],
            'is_required' => ['sometimes', 'boolean'],
        ]);

        $field = CustomField::create($data + ['sort_order' => (int) CustomField::max('sort_order') + 1]);

        return ApiResponse::created(['custom_field' => ['id' => $field->uuid] + $data]);
    }

    public function updateCustomField(Request $request, string $uuid)
    {
        $this->authorize('update', $request->user()->tenant);

        $field = CustomField::where('uuid', $uuid)->firstOrFail();

        $field->update($request->validate([
            'label' => ['sometimes', 'string', 'max:120'],
            'options' => ['nullable', 'array'],
            'is_required' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]));

        return ApiResponse::success(['custom_field' => ['id' => $field->uuid, 'label' => $field->label, 'is_active' => $field->is_active]]);
    }
}
