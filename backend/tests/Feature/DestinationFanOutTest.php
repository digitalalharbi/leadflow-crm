<?php

namespace Tests\Feature;

use App\Domain\Tracking\Destinations\DestinationFanOut;
use App\Domain\Tracking\EventRecorder;
use App\Domain\Tracking\EventType;
use App\Models\DestinationDelivery;
use App\Models\Lead;
use App\Models\Project;
use App\Models\TrackingDestination;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * An event happens once and is told to each destination once: the same id the browser's pixel used, only to
 * those that asked for it, only with consent, never with a name or a number in the clear, and never at the
 * cost of the lead — a destination that is down is a delivery that failed, not a form that did not submit.
 */
class DestinationFanOutTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private array $rival;

    private Project $project;

    private TrackingLink $link;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Fan Co');
        $this->rival = $this->provisionTenant('Rival Fan Co');
        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
        $this->link = $this->asTenant($this->t['tenant'], fn () => TrackingLink::create(['token' => 'fan'.random_int(1000000, 9999999), 'name' => 'إعلان', 'channel' => 'meta', 'project_id' => $this->project->id, 'utm_source' => 'meta']));
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
    }

    private function destination(string $kind, array $config, array $credentials = [], array $extra = [], ?array $tenant = null): TrackingDestination
    {
        return $this->asTenant(($tenant ?? $this->t)['tenant'], fn () => TrackingDestination::create($extra + [
            'kind' => $kind, 'name' => $kind, 'is_enabled' => true, 'config' => $config, 'credentials' => $credentials,
            'send_value' => true, 'hash_identifiers' => true, 'consent_mode' => 'required',
        ]));
    }

    private function lead(array $overrides = []): Lead
    {
        return $this->asTenant($this->t['tenant'], function () use ($overrides) {
            $lead = Lead::create(['full_name' => 'سلمان القحطاني', 'phone' => '0551234567', 'phone_normalized' => $overrides['phone_normalized'] ?? '+966551234567', 'email' => 'salman@example.com', 'status' => 'new', 'project_id' => $this->project->id]);
            $lead->forceFill($overrides + ['tracking_consent' => 'granted', 'visitor_id' => 'v_'.str_repeat('a', 20), 'first_touch_link_id' => $this->link->id, 'last_touch_link_id' => $this->link->id, 'click_ids' => ['last' => ['fbclid' => 'FB777', 'ttclid' => 'TT777']]])->saveQuietly();

            return $lead->fresh();
        });
    }

    private function event(EventType $type, Lead $lead, array $extra = []): ?TrackingEvent
    {
        return $this->asTenant($this->t['tenant'], fn () => app(EventRecorder::class)->record($type, $extra + [
            'lead_id' => $lead->id, 'visitor_id' => $lead->visitor_id, 'session_id' => 's_'.str_repeat('b', 20), 'tracking_link_id' => $this->link->id,
            'project_id' => $lead->project_id, 'platform' => 'meta', 'consent' => $lead->tracking_consent, 'url' => 'https://nargis.example/landing',
        ]));
    }

    private function deliveries(?string $status = null)
    {
        return DestinationDelivery::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->when($status, fn ($q) => $q->where('status', $status))->get();
    }

    private function sent(string $needle): array
    {
        $bodies = [];

        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), $needle)) {
                $bodies[] = ['url' => $request->url(), 'data' => $request->data(), 'raw' => $request->body(), 'headers' => $request->headers()];
            }
        }

        return $bodies;
    }

    // ---------------------------------------------------------------- each destination, in its own words

    public function test_a_qualified_lead_reaches_ga4_with_a_client_and_no_personal_data(): void
    {
        $this->destination('ga4', ['measurement_id' => 'G-ABC123DEF4'], ['api_secret' => 'ga-secret']);
        $lead = $this->lead();

        $event = $this->event(EventType::Qualified, $lead);

        $calls = $this->sent('google-analytics.com/mp/collect');
        $this->assertCount(1, $calls);
        $this->assertStringContainsString('measurement_id=G-ABC123DEF4', $calls[0]['url']);
        $this->assertStringContainsString('api_secret=ga-secret', $calls[0]['url']);
        $this->assertSame('v_'.str_repeat('a', 20), $calls[0]['data']['client_id']);
        $this->assertSame('qualify_lead', $calls[0]['data']['events'][0]['name']);
        $this->assertSame($event->event_id, $calls[0]['data']['events'][0]['params']['event_id']);
        $this->assertSame($this->link->uuid, $calls[0]['data']['events'][0]['params']['tracking_link_id']);
        $this->assertSame($this->project->uuid, $calls[0]['data']['events'][0]['params']['project_id']);
        foreach (['سلمان', '0551234567', 'salman@example.com'] as $private) {
            $this->assertStringNotContainsString($private, $calls[0]['raw'], 'GA4 was told something personal');
        }
        $this->assertSame('sent', $this->deliveries()->first()->status);
    }

    public function test_the_form_submit_reaches_meta_under_the_id_the_pixel_used_with_hashed_identifiers(): void
    {
        $this->destination('meta', ['pixel_id' => '123456789012345'], ['access_token' => 'meta-token']);
        $lead = $this->lead();

        $event = $this->event(EventType::FormSubmit, $lead, ['event_id' => '11111111-2222-3333-4444-555555555555']);

        $calls = $this->sent('graph.facebook.com/v21.0/123456789012345/events');
        $this->assertCount(1, $calls);
        $row = $calls[0]['data']['data'][0];
        $this->assertSame('Lead', $row['event_name']);
        $this->assertSame('11111111-2222-3333-4444-555555555555', $row['event_id'], 'the server and the pixel must share the id, or Meta counts the lead twice');
        $this->assertSame('website', $row['action_source']);
        $this->assertSame('https://nargis.example/landing', $row['event_source_url']);
        $this->assertSame(hash('sha256', 'salman@example.com'), $row['user_data']['em'][0] ?? $row['user_data']['em']);
        $this->assertStringContainsString('FB777', (string) ($row['user_data']['fbc'] ?? ''), 'the click id is what lets Meta credit the ad');
        foreach (['سلمان', '0551234567', 'salman@example.com'] as $private) {
            $this->assertStringNotContainsString($private, $calls[0]['raw'], 'Meta was sent a name, a number or an address in the clear');
        }
    }

    public function test_the_form_submit_reaches_tiktok_and_a_webhook_and_a_gtm_server_container(): void
    {
        $this->destination('tiktok', ['pixel_id' => 'CABC123DEF456GH'], ['access_token' => 'tt-token']);
        $this->destination('webhook', ['endpoint_url' => 'https://hooks.example.test/in'], ['secret' => 'whsec']);
        $this->destination('gtm_server', ['endpoint_url' => 'https://gtm.example.test/collect']);
        $lead = $this->lead();

        $event = $this->event(EventType::FormSubmit, $lead);

        $tt = $this->sent('business-api.tiktok.com')[0];
        $this->assertSame('SubmitForm', $tt['data']['data'][0]['event']);
        $this->assertSame($event->event_id, $tt['data']['data'][0]['event_id']);

        $hook = $this->sent('hooks.example.test')[0];
        $this->assertSame('form_submitted', $hook['data']['event']);
        $this->assertSame($event->event_id, $hook['data']['event_id']);
        $signature = collect($hook['headers'])->first(fn ($v, $k) => strtolower($k) === 'x-webhook-signature');
        $this->assertSame('sha256='.hash_hmac('sha256', $hook['raw'], 'whsec'), is_array($signature) ? $signature[0] : $signature, 'the receiver cannot tell it is ours');

        $gtm = $this->sent('gtm.example.test')[0];
        $this->assertSame('form_submitted', $gtm['data']['event_name']);
    }

    // ---------------------------------------------------------------- what is sent, and what is not

    public function test_without_consent_nothing_leaves_and_the_delivery_says_why(): void
    {
        $this->destination('webhook', ['endpoint_url' => 'https://hooks.example.test/in'], ['secret' => 'whsec']);
        $lead = $this->lead(['tracking_consent' => null]);

        $this->event(EventType::Qualified, $lead, ['consent' => null]);

        Http::assertNothingSent();
        $this->assertSame('skipped', $this->deliveries()->first()->status);
        $this->assertSame('consent_missing', $this->deliveries()->first()->reason);
    }

    public function test_a_denied_consent_is_never_overridden_and_an_unknown_one_may_be_allowed_by_the_workspace(): void
    {
        $this->destination('webhook', ['endpoint_url' => 'https://hooks.example.test/in'], ['secret' => 'whsec'], ['consent_mode' => 'unless_denied']);

        $unknown = $this->lead(['tracking_consent' => null]);
        $this->event(EventType::Qualified, $unknown, ['consent' => null]);
        Http::assertSentCount(1);

        $denied = $this->lead(['tracking_consent' => 'denied', 'phone_normalized' => '+966552222222']);
        $this->event(EventType::Qualified, $denied, ['consent' => 'denied']);
        Http::assertSentCount(1);
        $this->assertSame('consent_denied', $this->deliveries('skipped')->first()->reason);
    }

    public function test_an_event_the_destination_was_not_asked_for_is_not_sent(): void
    {
        $this->destination('webhook', ['endpoint_url' => 'https://hooks.example.test/in'], ['secret' => 'whsec'], ['events' => ['won']]);

        $this->event(EventType::Qualified, $this->lead());

        Http::assertNothingSent();
        $this->assertSame('not_allowed', $this->deliveries()->first()->reason);
    }

    public function test_money_is_sent_only_when_the_workspace_allows_it(): void
    {
        $this->destination('webhook', ['endpoint_url' => 'https://hooks.example.test/in'], ['secret' => 'whsec'], ['name' => 'with value']);
        $this->destination('gtm_server', ['endpoint_url' => 'https://gtm.example.test/collect'], [], ['name' => 'no value', 'send_value' => false]);
        $lead = $this->lead(['status' => 'won', 'won_at' => now(), 'first_contact_at' => now()->subDay(), 'last_contact_at' => now()->subDay(), 'deal_value_minor' => 90000000]);

        $this->event(EventType::Revenue, $lead, ['meta' => ['deal_value_minor' => 90000000]]);

        $this->assertEquals(900000.0, (float) $this->sent('hooks.example.test')[0]['data']['value']);
        $this->assertSame('SAR', $this->sent('hooks.example.test')[0]['data']['currency']);
        $this->assertArrayNotHasKey('value', $this->sent('gtm.example.test')[0]['data'], 'a destination that was not to see the money saw it');
    }

    public function test_identifiers_are_hashed_only_when_the_workspace_and_the_visitor_allow_it(): void
    {
        $this->destination('meta', ['pixel_id' => '123456789012345'], ['access_token' => 'meta-token'], ['hash_identifiers' => false]);

        $this->event(EventType::FormSubmit, $this->lead());

        $row = $this->sent('graph.facebook.com')[0]['data']['data'][0];
        $this->assertArrayNotHasKey('em', $row['user_data'] ?? []);
        $this->assertArrayNotHasKey('ph', $row['user_data'] ?? []);
    }

    public function test_an_ad_platform_that_already_gets_the_stages_is_not_told_them_twice(): void
    {
        $this->destination('meta', ['pixel_id' => '123456789012345'], ['access_token' => 'meta-token']);

        $this->event(EventType::Qualified, $this->lead());

        Http::assertNothingSent();
        $this->assertSame('via_conversions', $this->deliveries()->first()->reason);
    }

    // ---------------------------------------------------------------- once, and never at the lead's cost

    public function test_an_event_is_one_delivery_however_often_it_is_fanned_out(): void
    {
        $this->destination('webhook', ['endpoint_url' => 'https://hooks.example.test/in'], ['secret' => 'whsec']);
        $event = $this->event(EventType::Qualified, $this->lead());

        $this->asTenant($this->t['tenant'], function () use ($event) {
            app(DestinationFanOut::class)->queue($event);
            app(DestinationFanOut::class)->queue($event);
        });

        $this->assertCount(1, $this->deliveries());
        Http::assertSentCount(1);
    }

    public function test_a_destination_that_is_down_costs_a_delivery_not_the_event_or_the_lead(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('boom', 500)]);
        $this->destination('webhook', ['endpoint_url' => 'https://hooks.example.test/in'], ['secret' => 'whsec']);
        $lead = $this->lead();

        $event = $this->event(EventType::Qualified, $lead);

        $this->assertNotNull($event, 'the event was lost with the destination');
        $this->assertNotNull($lead->fresh(), 'the lead was lost');
        $delivery = $this->deliveries()->first();
        $this->assertSame('failed', $delivery->status);
        $this->assertStringContainsString('500', (string) $delivery->last_error);
        $this->assertGreaterThanOrEqual(1, $delivery->attempts);
        $this->assertNotNull(TrackingDestination::withoutGlobalScopes()->first()->last_error, 'the destination does not show that it is failing');
    }

    public function test_a_destination_waiting_for_its_credential_waits_and_does_not_retry(): void
    {
        $this->destination('ga4', ['measurement_id' => 'G-ABC123DEF4'], []);

        $this->event(EventType::Qualified, $this->lead());

        Http::assertNothingSent();
        $this->assertSame('awaiting_credentials', $this->deliveries()->first()->status);
    }

    // ---------------------------------------------------------------- whose events

    public function test_a_projects_destination_hears_only_that_projects_events_and_the_workspaces_hears_all(): void
    {
        $other = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'الياسمين']));
        $this->destination('webhook', ['endpoint_url' => 'https://hooks.example.test/project'], ['secret' => 's'], ['project_id' => $other->id, 'name' => 'other project']);
        $this->destination('gtm_server', ['endpoint_url' => 'https://gtm.example.test/all'], [], ['project_id' => null, 'name' => 'everything']);

        $this->event(EventType::Qualified, $this->lead());

        $this->assertEmpty($this->sent('hooks.example.test/project'), 'another project\'s destination heard this project');
        $this->assertCount(1, $this->sent('gtm.example.test/all'));
    }

    public function test_another_workspaces_destination_never_hears_this_workspaces_events(): void
    {
        $this->destination('webhook', ['endpoint_url' => 'https://rival.example.test/in'], ['secret' => 's'], [], $this->rival);

        $this->event(EventType::Qualified, $this->lead());

        $this->assertEmpty($this->sent('rival.example.test'), 'a rival heard this workspace\'s lead');
        $this->assertCount(0, DestinationDelivery::withoutGlobalScopes()->where('tenant_id', $this->rival['tenant']->id)->get());
    }

    public function test_a_disabled_destination_hears_nothing(): void
    {
        $this->destination('webhook', ['endpoint_url' => 'https://hooks.example.test/in'], ['secret' => 's'], ['is_enabled' => false]);

        $this->event(EventType::Qualified, $this->lead());

        Http::assertNothingSent();
        $this->assertCount(0, $this->deliveries());
    }
}
