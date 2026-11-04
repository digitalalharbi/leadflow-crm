<?php

namespace App\Http\Controllers\Api;

use App\DataTransferObjects\IncomingLead;
use App\Domain\Leads\LeadTags;
use App\Enums\IntegrationProvider;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Leads\LeadIngestionService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * The marketing site's own enquiries: a demo request or an insights signup becomes a lead in Mohtam's own workspace, in
 * the same pipeline as any other lead, so there is no second list. Asked for with consent, and it answers the same
 * whether the person was new or known.
 */
class PublicContactController extends Controller
{
    /** Whether there is somewhere for it to go. With no workspace named, the page offers email and phone instead. */
    public function show()
    {
        return ApiResponse::success(['form' => $this->workspace() !== null]);
    }

    public function store(Request $request, LeadIngestionService $ingestion, TenantContext $context, LeadTags $tags)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['demo', 'contact', 'insights'])],
            'name' => ['required_unless:kind,insights', 'nullable', 'string', 'max:120'],
            'email' => ['required_without:phone', 'nullable', 'email', 'max:190'],
            'phone' => ['required_without:email', 'nullable', 'string', 'max:32'],
            'company' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:1000'],
            'consent' => ['accepted'],
            // A field no person sees. Filled in, it is a bot, and it is told all went well.
            'website' => ['nullable', 'string', 'max:0'],
            'utm_source' => ['nullable', 'string', 'max:120'],
            'utm_medium' => ['nullable', 'string', 'max:120'],
            'utm_campaign' => ['nullable', 'string', 'max:120'],
        ]);

        $tenant = $this->workspace();
        abort_if($tenant === null, 404);

        $result = $ingestion->ingest($tenant, IncomingLead::fromArray(IntegrationProvider::GenericWebhook, array_filter([
            'full_name' => $data['name'] ?? ($data['email'] ?? null),
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'platform' => 'website',
            'source' => 'marketing_site',
            'campaign_name' => $data['kind'],
            'utm_source' => $data['utm_source'] ?? null,
            'utm_medium' => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'custom' => array_filter(['company' => $data['company'] ?? null, 'message' => $data['message'] ?? null]) ?: null,
        ], fn ($value) => $value !== null)));

        if ($result->lead !== null) {
            $context->withTenant($tenant, fn () => $tags->add($result->lead, $data['kind'] === 'insights' ? 'insights_subscriber' : 'demo_request', null));
        } else {
            Log::info('marketing_contact.refused', ['reason' => $result->reason]);
        }

        return ApiResponse::success(null, 'Received.');
    }

    private function workspace(): ?Tenant
    {
        $slug = config('platform.marketing_workspace');

        return filled($slug) ? Tenant::query()->where('slug', $slug)->where('is_active', true)->first() : null;
    }
}
