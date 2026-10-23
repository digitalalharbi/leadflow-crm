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
use App\Models\LandingForm;
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
        private TenantContext $context,
    ) {}

    /**
     * @param  array<string,mixed>  $in  the validated body
     * @param  array{ip?:?string, user_agent?:?string, referer?:?string}  $request
     * @return array{status:int, body:array<string,mixed>}
     */
    public function handle(TrackingLink $link, LandingForm $form, Tenant $tenant, array $in, array $request): array
    {
        return $this->context->withTenant($tenant, function () use ($link, $form, $tenant, $in, $request) {
            // A bot fills what a person cannot see. It is thanked and nothing is made.
            if (filled($in['website'] ?? null)) {
                return $this->ok($form, $in['submission_id'], duplicate: false);
            }

            if ($form->consent_required && ($in['consent'] ?? false) !== true) {
                return ['status' => 422, 'body' => ['success' => false, 'message' => 'Consent is required.', 'errors' => ['consent' => 'required']]];
            }

            // The same submission twice (a retry, a double tap) is the first one.
            if (TrackingEvent::where('event_type', EventType::FormSubmit->value)->where('event_id', $in['submission_id'])->exists()) {
                return $this->ok($form, $in['submission_id'], duplicate: true);
            }

            $fields = $this->schema->normalize($form->fields ?? []);
            $result = $this->schema->validateSubmission($fields, (array) ($in['answers'] ?? []), $this->context($link, $in));

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

            $incoming = $this->incoming($link, $form, $fields, $result['values'], $in, $visitor, $session->session_id, $clickIds, $utm, $agent);

            $ingested = $this->ingestion->ingest($tenant, $incoming);

            if (! $ingested->wasAccepted()) {
                return ['status' => 422, 'body' => ['success' => false, 'message' => 'We could not take these details.', 'errors' => ['_form' => 'rejected']]];
            }

            $lead = $ingested->lead;

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
                'meta' => ['form' => $form->uuid, 'duplicate_customer' => $ingested->isDuplicate()],
            ] + $agent);

            return $this->ok($form, $in['submission_id'], duplicate: false, link: $link, projectUuid: Project::whereKey($lead->project_id)->value('uuid'));
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
    private function incoming(TrackingLink $link, LandingForm $form, array $fields, array $values, array $in, string $visitor, string $sessionId, array $clickIds, array $utm, array $agent): IncomingLead
    {
        $lead = $this->schema->toLead($fields, $values);
        $tracking = $lead['tracking'];

        // A custom field holds text: a list of choices reads as the labels the visitor saw, a tick as yes or no.
        $lead['custom'] = $this->asText($fields, $lead['custom']);

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
            'form_name' => $form->name,
            'form_id' => $form->uuid,
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
            'form' => $form->uuid,
            'submission_id' => $in['submission_id'],
        ];

        return IncomingLead::fromArray(IntegrationProvider::LandingForm, $data, $raw);
    }

    /**
     * @param  list<array<string,mixed>>  $fields
     * @param  array<string,mixed>  $custom
     * @return array<string,string>
     */
    private function asText(array $fields, array $custom): array
    {
        $labels = [];
        foreach ($fields as $f) {
            if (str_starts_with((string) $f['mapping'], 'custom.')) {
                $labels[substr($f['mapping'], 7)] = array_column($f['options'] ?? [], 'label', 'value');
            }
        }

        $text = [];

        foreach ($custom as $key => $value) {
            $text[$key] = match (true) {
                is_array($value) => implode('، ', array_map(fn ($v) => $labels[$key][$v] ?? (string) $v, $value)),
                is_bool($value) => $value ? 'نعم' : 'لا',
                default => (string) ($labels[$key][(string) $value] ?? $value),
            };
        }

        return $text;
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function ok(LandingForm $form, string $submissionId, bool $duplicate, ?TrackingLink $link = null, ?string $projectUuid = null): array
    {
        return ['status' => 200, 'body' => ['success' => true, 'data' => [
            'ok' => true,
            'duplicate' => $duplicate,
            'event_id' => $submissionId,
            'thank_you' => ['title' => $form->thank_you_title, 'message' => $form->thank_you_message],
            // What the page pushes to its dataLayer — the same id a browser pixel uses, so a platform counts it once.
            'datalayer' => array_filter([
                'event' => EventType::FormSubmit->canonical(),
                'event_id' => $submissionId,
                'project_id' => $projectUuid,
                'tracking_link_id' => $link?->uuid,
                'lead_stage' => 'new',
            ]),
        ], 'message' => null, 'errors' => null]];
    }
}
