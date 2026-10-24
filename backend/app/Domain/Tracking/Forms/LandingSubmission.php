<?php

namespace App\Domain\Tracking\Forms;

use App\DataTransferObjects\IncomingLead;
use App\Domain\Tracking\Attribution;
use App\Domain\Tracking\ClickIds;
use App\Domain\Tracking\EventRecorder;
use App\Domain\Tracking\EventType;
use App\Domain\Tracking\Sessions;
use App\Domain\Tracking\Visitor;
use App\Enums\IntegrationProvider;
use App\Models\LandingFormVersion;
use App\Models\LeadSourceEvent;
use App\Models\Project;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;
use App\Services\Leads\LeadIngestionService;
use App\Support\TenantContext;

/**
 * What happens when somebody presses the button.
 *
 *   validate → ingest (normalise, de-duplicate, attribute, project, assign, automation — the one pipeline every
 *   lead takes) → record the submit with the id the browser's pixel shared → answer
 *
 * One submit is one event and one lead whatever the network does: a retry carries the same submission id and is
 * recognised, the same customer filling the form twice is one lead, and a refused answer makes nothing at all.
 * Nothing the visitor sends can choose what the server decides — the project, the source, the hidden fields.
 */
class LandingSubmission
{
    private const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    public function __construct(
        private FormSchema $schema,
        private Sessions $sessions,
        private EventRecorder $events,
        private LeadIngestionService $ingestion,
        private FormSignals $signals,
        private TenantContext $context,
    ) {}

    /**
     * @param  array<string,mixed>  $in  the validated body
     * @param  array{ip?:?string, user_agent?:?string, referer?:?string}  $request
     * @return array{status:int, body:array<string,mixed>}
     */
    public function handle(TrackingLink $link, LandingFormVersion $version, Tenant $tenant, array $in, array $request): array
    {
        // What was live when the visitor opened the page is what they are held to: the version, never the draft.
        $def = $version->snapshot;

        return $this->context->withTenant($tenant, function () use ($link, $version, $def, $tenant, $in, $request) {
            // A bot fills what a person cannot see. It is thanked and nothing is made.
            if (filled($in['website'] ?? null)) {
                return $this->ok($def, $in['submission_id'], duplicate: false);
            }

            if (($def['consent_required'] ?? true) && ($in['consent'] ?? false) !== true) {
                return ['status' => 422, 'body' => ['success' => false, 'message' => 'Consent is required.', 'errors' => ['consent' => 'required']]];
            }

            // The same submission twice (a retry, a double tap) is the first one.
            if (TrackingEvent::where('event_type', EventType::FormSubmit->value)->where('event_id', $in['submission_id'])->exists()) {
                return $this->ok($def, $in['submission_id'], duplicate: true, link: $link);
            }

            $fields = $this->schema->normalize($def['fields'] ?? []);
            $result = $this->schema->validateSubmission($fields, (array) ($in['answers'] ?? []), $this->context($link, $in), $def['layout'] ?? null, ['default_country' => $def['settings']['default_country'] ?? 'SA']);

            if ($result['errors'] !== []) {
                return ['status' => 422, 'body' => ['success' => false, 'message' => 'Some answers need attention.', 'errors' => $result['errors']]];
            }

            $visitor = Visitor::clean($in['visitor_id'] ?? null) ?? Visitor::newId();
            $clickIds = ClickIds::from((array) ($in['click_ids'] ?? []));
            $utm = array_filter(array_intersect_key($in, array_flip(self::UTM_KEYS)));
            $agent = Visitor::agent($request['user_agent'] ?? null);

            $session = $this->sessions->touch($visitor, $in['session_id'] ?? null, $link, [
                'click_ids' => $clickIds, 'utm' => $utm, 'landing_url' => $in['url'] ?? null, 'referrer' => $in['referrer'] ?? ($request['referer'] ?? null),
                'ip_hash' => Visitor::ipHash($request['ip'] ?? null), 'consent' => ($in['consent'] ?? false) === true ? 'granted' : 'denied',
            ] + $agent);

            $incoming = $this->incoming($link, $version, $def, $fields, $result, $in, $visitor, $session->session_id, $clickIds, $utm, $agent);

            $ingested = $this->ingestion->ingest($tenant, $incoming);

            if (! $ingested->wasAccepted()) {
                return ['status' => 422, 'body' => ['success' => false, 'message' => 'We could not take these details.', 'errors' => ['_form' => 'rejected']]];
            }

            $lead = $ingested->lead;

            // Which version of the form this customer saw, on the source event as well as on the trail.
            LeadSourceEvent::where('lead_id', $lead->id)->where('provider_lead_id', $in['submission_id'])->update(['form_version_id' => $version->id]);
            $this->signals->apply($lead, (array) ($def['settings']['signals'] ?? []), $result['values']);

            $this->events->record(EventType::FormSubmit, [
                'tracking_link_id' => $link->id,
                'lead_id' => $lead->id,
                'visitor_id' => $visitor,
                'session_id' => $session->session_id,
                'event_id' => $in['submission_id'],
                'project_id' => $lead->project_id ?? $link->project_id,
                'property_id' => $link->property_id,
                'campaign_id' => $link->campaign_id,
                'platform' => Attribution::platformOf($link->channel),
                'consent' => $session->consent,
                'url' => $in['url'] ?? null,
                'referrer' => $in['referrer'] ?? ($request['referer'] ?? null),
                'click_id' => ClickIds::primary($clickIds),
                'ip_hash' => Visitor::ipHash($request['ip'] ?? null),
                'form_version_id' => $version->id,
                // What was chosen on a matrix is aggregated on the form's analytics: choices only, never a name or a number.
                'meta' => array_filter(['form' => $version->form->uuid, 'version' => $version->version, 'duplicate_customer' => $ingested->isDuplicate(), 'matrix' => $this->matrixChoices($fields, $result['values'])]),
            ] + $agent);

            return $this->ok($def, $in['submission_id'], duplicate: false, link: $link, projectUuid: Project::whereKey($lead->project_id)->value('uuid'), version: $version);
        });
    }

    /**
     * What the server knows about the visit, for the form's hidden fields: the link's own say, never the browser's.
     *
     * @return array<string,string>
     */
    private function context(TrackingLink $link, array $in): array
    {
        return array_filter([
            'source' => $link->utm_source ?: (in_array($link->channel, ['other', 'landing_page', 'qr'], true) ? null : $link->channel),
            'medium' => $link->utm_medium,
            'campaign' => $link->utm_campaign,
            'content' => $link->utm_content,
            'term' => $link->utm_term,
            'project' => $link->project_id ? Project::whereKey($link->project_id)->value('name') : null,
            'unit' => $link->property_id ? Property::whereKey($link->property_id)->value('code') : null,
            'link' => $link->name,
            'landing_url' => $in['url'] ?? null,
            'referrer' => $in['referrer'] ?? null,
        ], fn ($v) => filled($v));
    }

    /**
     * @param  list<array<string,mixed>>  $fields
     * @param  array<string,mixed>  $values
     * @param  array<string,string>  $clickIds
     * @param  array<string,string>  $utm
     * @param  array<string,?string>  $agent
     */
    private function incoming(TrackingLink $link, LandingFormVersion $version, array $def, array $fields, array $result, array $in, string $visitor, string $sessionId, array $clickIds, array $utm, array $agent): IncomingLead
    {
        $values = $result['values'];
        $lead = $this->schema->toLead($fields, $values);
        $tracking = $lead['tracking'];

        // A custom field holds text: a list of choices reads as the labels the visitor saw, a tick as yes or no.
        $lead['custom'] = $this->schema->customAsText($fields, $lead['custom']);

        $data = array_diff_key($lead, array_flip(['tracking', 'raw'])) + [
            // The platform is the ad's (Meta, TikTok…), not "a landing page": that is how the page was reached, not who sent the customer.
            'platform' => Attribution::platformOf($link->channel) ?? 'landing_page',
            'source' => $tracking['source'] ?? $link->utm_source,
            'campaign_name' => $tracking['campaign'] ?? $link->utm_campaign,
            'utm_source' => $link->utm_source ?? ($utm['utm_source'] ?? null),
            'utm_medium' => $link->utm_medium ?? ($utm['utm_medium'] ?? null),
            'utm_campaign' => $link->utm_campaign ?? ($utm['utm_campaign'] ?? null),
            'utm_term' => $link->utm_term ?? ($utm['utm_term'] ?? null),
            'utm_content' => $link->utm_content ?? ($utm['utm_content'] ?? null),
            'project_id' => $link->project_id,
            'form_name' => $def['name'],
            'form_id' => $version->form->uuid,
            'provider_lead_id' => $in['submission_id'],
            'click_id' => ClickIds::primary($clickIds),
            'visitor_id' => $visitor,
            'tracking_token' => $link->token,
            'referrer' => $in['referrer'] ?? null,
            'landing_url' => $in['url'] ?? null,
            'device' => $agent['device'] ?? null,
            'browser' => $agent['browser'] ?? null,
        ];

        // Every answer is kept as it came; the visit's own metadata beside it, never mixed into what the customer said.
        $raw = [
            'answers' => $lead['raw'],
            'tracking' => $tracking + array_filter(['session_id' => $sessionId, 'click_ids' => $clickIds, 'utm' => $utm]),
            // The phone as the visitor typed it, beside the one the lead keeps.
            'typed' => $result['raw'] ?? [],
            'form' => $version->form->uuid,
            'form_version' => $version->version,
            'submission_id' => $in['submission_id'],
        ];

        return IncomingLead::fromArray(IntegrationProvider::LandingForm, $data, $raw);
    }

    /**
     * The choices made on matrix fields, row by row — what to aggregate, nothing that identifies anybody.
     *
     * @param  list<array<string,mixed>>  $fields
     * @param  array<string,mixed>  $values
     * @return array<string,array<string,mixed>>
     */
    private function matrixChoices(array $fields, array $values): array
    {
        $out = [];

        foreach ($fields as $f) {
            if ($f['type'] === 'matrix' && isset($values[$f['key']])) {
                $out[$f['key']] = $values[$f['key']];
            }
        }

        return $out;
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function ok(array $def, string $submissionId, bool $duplicate, ?TrackingLink $link = null, ?string $projectUuid = null, ?LandingFormVersion $version = null): array
    {
        $thanks = (array) ($def['settings']['thanks'] ?? []);

        return ['status' => 200, 'body' => ['success' => true, 'data' => [
            'ok' => true,
            'duplicate' => $duplicate,
            'event_id' => $submissionId,
            'thank_you' => [
                'title' => $def['thank_you_title'] ?? null, 'message' => $def['thank_you_message'] ?? null,
                'actions' => $thanks['actions'] ?? [],
                'redirect_url' => $thanks['redirect_url'] ?? null, 'redirect_delay' => $thanks['redirect_delay'] ?? 5,
            ],
            // What the page pushes to its dataLayer once — and only once — the lead is safely kept: the same id a browser pixel uses, so a
            // platform counts it once. Ids and names of campaigns; never a name, a number or an address of the customer.
            'datalayer' => array_filter([
                'event' => EventType::FormSubmit->canonical(),
                'event_id' => $submissionId,
                'project_id' => $projectUuid,
                'tracking_link_id' => $link?->uuid,
                'form_id' => $version?->form->uuid,
                'form_version' => $version?->version,
                'lead_stage' => 'new',
                'source' => $link?->utm_source,
                'medium' => $link?->utm_medium,
                'campaign' => $link?->utm_campaign,
            ]),
        ], 'message' => null, 'errors' => null]];
    }
}
