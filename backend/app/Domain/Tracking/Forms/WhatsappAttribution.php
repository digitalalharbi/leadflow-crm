<?php

namespace App\Domain\Tracking\Forms;

use App\DataTransferObjects\IncomingLead;
use App\Domain\Tracking\EventType;
use App\Domain\Tracking\WhatsappReference;
use App\Enums\IntegrationProvider;
use App\Models\LandingForm;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;
use App\Services\Leads\LeadIngestionService;
use App\Support\TenantContext;

/**
 * Ties a WhatsApp conversation to the page visit that started it, through the reference the page wrote in the message.
 *
 * An unknown number stays unknown unless its message carries the reference of a tap this workspace really recorded, from a page
 * set to make a lead on the first message. The reference is a claim made by whoever typed it, so it can only add a lead to the
 * project the tap belongs to, and it never moves or overwrites a lead that already has a page's credit.
 */
class WhatsappAttribution
{
    public function __construct(private LeadIngestionService $ingestion, private TenantContext $context) {}

    public function resolve(?Lead $lead, ?string $from, ?string $body): ?Lead
    {
        $ref = WhatsappReference::in($body);

        if ($ref === null) {
            return $lead;
        }

        $click = TrackingEvent::query()->where('event_type', EventType::WhatsappClick->value)->where('meta->ref', $ref)->where('occurred_at', '>=', now()->subDays(30))->latest('id')->first();
        $link = $click?->tracking_link_id ? TrackingLink::find($click->tracking_link_id) : null;
        $form = $link?->landing_form_id ? LandingForm::find($link->landing_form_id) : null;

        if ($form === null) {
            return $lead;
        }

        if ($lead !== null) {
            if ($lead->landing_form_id === null) {
                $lead->forceFill(['landing_form_id' => $form->id])->saveQuietly();
            }

            return $lead;
        }

        $mode = data_get($form->publishedVersion?->snapshot, 'page.cta.whatsapp.mode');

        if ($from === null || $mode !== 'inbound') {
            return null;
        }

        $tenant = Tenant::find($form->tenant_id);
        $result = $this->ingestion->ingest($tenant, IncomingLead::fromArray(IntegrationProvider::Whatsapp, [
            'phone' => $from,
            'project_id' => $form->project_id,
            'platform' => 'whatsapp',
            'source' => $link->utm_source ?? 'landing_page',
            'campaign_name' => $click->utm_campaign ?? $link->utm_campaign,
            'utm_source' => $click->utm_source ?? $link->utm_source,
            'utm_medium' => $click->utm_medium ?? $link->utm_medium,
            'utm_campaign' => $click->utm_campaign ?? $link->utm_campaign,
            'utm_content' => $click->utm_content ?? $link->utm_content,
            'utm_term' => $click->utm_term ?? $link->utm_term,
            'click_id' => $click->click_id,
            'visitor_id' => $click->visitor_id,
            'tracking_token' => $link->token,
            'form_name' => $form->name,
            'form_id' => $form->uuid,
        ]));

        $made = $result->wasAccepted() ? $result->lead : null;
        $made?->forceFill(['landing_form_id' => $form->id])->saveQuietly();

        return $made;
    }
}
