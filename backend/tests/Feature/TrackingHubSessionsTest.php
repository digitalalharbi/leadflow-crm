<?php

namespace Tests\Feature;

use App\DataTransferObjects\IncomingLead;
use App\Domain\Tracking\ClickIds;
use App\Domain\Tracking\EventType;
use App\Enums\IntegrationProvider;
use App\Models\Project;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;
use App\Models\VisitorSession;
use App\Services\Leads\LeadIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The first-party trail: a click starts a session, the session remembers the first and the last link that
 * touched it and every platform click id it arrived with, and every event carries an id the browser and
 * the server share. Nothing here is a second store — it is the one tracking_events table, grown.
 */
class TrackingHubSessionsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Hub Co');
        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function link(array $overrides = []): TrackingLink
    {
        return $this->asTenant($this->t['tenant'], fn () => TrackingLink::create($overrides + [
            'token' => 'tok'.random_int(1000000, 9999999), 'name' => 'إعلان ميتا', 'channel' => 'meta', 'project_id' => $this->project->id,
            'destination_url' => 'https://nargis.example/landing',
        ]));
    }

    private function sessions()
    {
        return VisitorSession::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->orderBy('id')->get();
    }

    private function events()
    {
        return TrackingEvent::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->orderBy('id')->get();
    }

    public function test_a_click_starts_a_session_with_the_platform_click_ids_and_gives_the_event_an_id(): void
    {
        $link = $this->link();

        $response = $this->get("/t/{$link->token}?gclid=G1&gbraid=GB1&wbraid=WB1&fbclid=FB1&ttclid=TT1&sccid=SC1&utm_campaign=ignored");

        $response->assertRedirect();
        $session = $this->sessions()->first();
        $this->assertNotNull($session, 'no session was started');
        $this->assertMatchesRegularExpression('/^s_[a-z0-9]{20}$/', $session->session_id);
        $this->assertSame($link->id, $session->first_link_id);
        $this->assertSame($link->id, $session->last_link_id);
        $this->assertSame(['gclid' => 'G1', 'gbraid' => 'GB1', 'wbraid' => 'WB1', 'fbclid' => 'FB1', 'ttclid' => 'TT1', 'sccid' => 'SC1'], $session->first_touch['click_ids']);
        $response->assertCookie('mt_sid', $session->session_id, false);

        $event = $this->events()->first();
        $this->assertSame($session->session_id, $event->session_id);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $event->event_id, 'the event has no id to share with the browser and the server');
        $this->assertSame('G1', $event->click_id, 'the click id the single column always held');
    }

    public function test_every_supported_click_id_is_read_and_nothing_else_is_kept(): void
    {
        $found = ClickIds::from(['gclid' => 'a', 'gbraid' => 'b', 'wbraid' => 'c', 'fbclid' => 'd', 'ttclid' => 'e', 'sccid' => 'f', 'msclkid' => 'g', 'li_fat_id' => 'h', 'twclid' => 'i', 'email' => 'x@y.z', 'gclid2' => 'no', 'fbclid_long' => str_repeat('x', 600)]);

        $this->assertSame(['gclid', 'gbraid', 'wbraid', 'fbclid', 'ttclid', 'sccid', 'msclkid', 'li_fat_id', 'twclid'], array_keys($found));
        $this->assertSame([], ClickIds::from(['gclid' => str_repeat('x', 600)]), 'an absurdly long value is not a click id');
        $this->assertSame([], ClickIds::from(['gclid' => ['array']]));
    }

    public function test_a_second_link_in_the_same_session_changes_the_last_touch_and_never_the_first(): void
    {
        $first = $this->link(['name' => 'ميتا']);
        $second = $this->link(['name' => 'تيك توك', 'channel' => 'tiktok']);

        $r1 = $this->get("/t/{$first->token}?fbclid=FB1");
        $sid = $this->sessions()->first()->session_id;
        $vid = $r1->getCookie('mt_vid', false)->getValue();

        $this->withUnencryptedCookies(['mt_vid' => $vid, 'mt_sid' => $sid])->get("/t/{$second->token}?ttclid=TT9")->assertRedirect();

        $this->assertCount(1, $this->sessions(), 'the same visit became two sessions');
        $session = $this->sessions()->first();
        $this->assertSame($first->id, $session->first_link_id);
        $this->assertSame($second->id, $session->last_link_id);
        $this->assertSame(['fbclid' => 'FB1'], $session->first_touch['click_ids']);
        $this->assertSame(['ttclid' => 'TT9'], $session->last_touch['click_ids']);
        $this->assertSame(2, $session->events_count);
    }

    public function test_an_idle_session_ends_and_a_new_one_starts_for_the_same_visitor(): void
    {
        Carbon::setTestNow('2026-10-23 10:00:00');
        $link = $this->link();
        $r1 = $this->get("/t/{$link->token}");
        $vid = $r1->getCookie('mt_vid', false)->getValue();
        $old = $this->sessions()->first()->session_id;

        Carbon::setTestNow('2026-10-23 11:00:00');
        $this->withUnencryptedCookies(['mt_vid' => $vid, 'mt_sid' => $old])->get("/t/{$link->token}");

        $this->assertCount(2, $this->sessions(), 'an hour later is a new session');
        $this->assertSame(1, $this->sessions()->pluck('visitor_id')->unique()->count(), 'but the same visitor');
    }

    public function test_a_landing_link_hands_the_visitor_and_the_session_to_the_landing_page(): void
    {
        $link = $this->link(['mode' => 'landing', 'destination_url' => null]);

        $response = $this->get("/t/{$link->token}");

        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('/l/'.$link->token, $location, 'it did not open the landing page');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertMatchesRegularExpression('/^v_[a-z0-9]{20}$/', $query['mt_vid'] ?? '');
        $this->assertMatchesRegularExpression('/^s_[a-z0-9]{20}$/', $query['mt_sid'] ?? '');
    }

    public function test_the_beacon_takes_the_new_events_and_a_retried_one_is_the_same_event(): void
    {
        $link = $this->link();
        $sid = 's_'.str_repeat('a', 20);
        $vid = 'v_'.str_repeat('b', 20);
        $body = ['event' => 'form_step', 'visitor_id' => $vid, 'session_id' => $sid, 'event_id' => '11111111-2222-3333-4444-555555555555', 'meta' => ['step' => 1]];

        $this->postJson("/api/v1/track/{$link->token}", $body)->assertNoContent();
        $this->postJson("/api/v1/track/{$link->token}", $body)->assertNoContent();

        $steps = $this->events()->where('event_type', EventType::FormStep->value);
        $this->assertCount(1, $steps, 'the retry was counted twice');
        $this->assertSame($sid, $steps->first()->session_id);
        $this->assertSame('11111111-2222-3333-4444-555555555555', $steps->first()->event_id);
    }

    public function test_a_browser_may_not_claim_what_only_the_server_can_know(): void
    {
        $link = $this->link();

        foreach (['lead_created', 'qualified', 'interested', 'appointment_booked', 'lost', 'revenue', 'won', 'reservation'] as $claim) {
            $this->postJson("/api/v1/track/{$link->token}", ['event' => $claim, 'visitor_id' => 'v_'.str_repeat('c', 20)])->assertStatus(422);
        }

        $this->assertCount(0, $this->events());
    }

    public function test_the_lead_carries_its_first_and_last_click_ids_and_the_session_knows_the_lead(): void
    {
        $first = $this->link(['name' => 'جوجل', 'channel' => 'google']);
        $second = $this->link(['name' => 'ميتا', 'channel' => 'meta']);

        $r1 = $this->get("/t/{$first->token}?gclid=GFIRST");
        $vid = $r1->getCookie('mt_vid', false)->getValue();
        $sid = $this->sessions()->first()->session_id;
        $this->withUnencryptedCookies(['mt_vid' => $vid, 'mt_sid' => $sid])->get("/t/{$second->token}?fbclid=FBLAST");

        $result = app(LeadIngestionService::class)->ingest($this->t['tenant'], IncomingLead::fromArray(IntegrationProvider::GenericWebhook, [
            'full_name' => 'سلمان', 'phone' => '+966551234567', 'visitor_id' => $vid, 'tracking_token' => $second->token,
        ]));

        $lead = $this->asTenant($this->t['tenant'], fn () => $result->lead->fresh());
        $this->assertSame($first->id, $lead->first_touch_link_id);
        $this->assertSame($second->id, $lead->last_touch_link_id);
        // jsonb keeps no key order, so the shape is what is compared.
        $this->assertSame(['gclid' => 'GFIRST'], $lead->click_ids['first']);
        $this->assertSame(['fbclid' => 'FBLAST'], $lead->click_ids['last']);
        $this->assertSame($lead->id, $this->sessions()->first()->lead_id, 'the visit does not know who it was');
    }

    public function test_a_session_and_its_events_belong_to_one_workspace(): void
    {
        $rival = $this->provisionTenant('Rival Hub Co');
        $mine = $this->link();
        $this->get("/t/{$mine->token}?gclid=SECRET");

        $this->assertCount(1, $this->sessions());
        $this->assertCount(0, VisitorSession::withoutGlobalScopes()->where('tenant_id', $rival['tenant']->id)->get());
        $this->assertCount(0, $this->asTenant($rival['tenant'], fn () => VisitorSession::all()), 'the rival workspace can read a session that is not its own');
        $this->assertCount(0, $this->asTenant($rival['tenant'], fn () => TrackingEvent::where('click_id', 'SECRET')->get()));
    }
}
