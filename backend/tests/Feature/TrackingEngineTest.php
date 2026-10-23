<?php

namespace Tests\Feature;

use App\Domain\Tracking\Attribution;
use App\Domain\Tracking\EventType;
use App\Domain\Tracking\Visitor;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Project;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Smart tracking links: /t/{token}, what a click records, how a visitor becomes a lead on the
 * same trail, and who is credited under each attribution model.
 */
class TrackingEngineTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Track Co');
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

    private function events(?TrackingLink $link = null)
    {
        return $this->asTenant($this->t['tenant'], fn () => TrackingEvent::query()->when($link, fn ($q) => $q->where('tracking_link_id', $link->id))->orderBy('id')->get());
    }

    // ------------------------------------------------------------ making links

    public function test_a_manager_makes_a_link_that_knows_what_it_is_for(): void
    {
        $campaign = $this->asTenant($this->t['tenant'], fn () => Campaign::create(['platform' => 'meta', 'external_id' => 'c1', 'name' => 'حملة أكتوبر', 'origin' => 'manual']));

        $response = $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/links', [
            'name' => 'لوحة طريق الملك فهد', 'channel' => 'qr', 'kind' => 'qr', 'project_id' => $this->project->uuid, 'campaign_id' => $campaign->uuid,
            'branch' => 'الرياض', 'destination_url' => 'https://nargis.example/p', 'utm_medium' => 'billboard',
        ])->assertCreated();

        $response->assertJsonPath('data.link.channel', 'qr')
            ->assertJsonPath('data.link.project.name', 'النرجس')
            ->assertJsonPath('data.link.campaign.name', 'حملة أكتوبر')
            ->assertJsonPath('data.link.utm.medium', 'billboard');

        $token = $response->json('data.link.token');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{10}$/', $token);
        $this->assertSame(rtrim(config('app.url'), '/').'/t/'.$token, $response->json('data.link.url'));
        $this->assertSame(1, $this->asTenant($this->t['tenant'], fn () => AuditLog::where('action', 'tracking_link.created')->count()));
    }

    public function test_a_sales_rep_can_read_links_but_not_make_or_change_them(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $link = $this->link();

        $this->actingAs($rep)->getJson('/api/v1/tracking/links')->assertOk()->assertJsonCount(1, 'data.links');
        $this->actingAs($rep)->postJson('/api/v1/tracking/links', ['name' => 'x'])->assertForbidden();
        $this->actingAs($rep)->patchJson("/api/v1/tracking/links/{$link->uuid}", ['name' => 'y'])->assertForbidden();
        $this->actingAs($rep)->deleteJson("/api/v1/tracking/links/{$link->uuid}")->assertForbidden();
    }

    public function test_a_link_cannot_point_at_another_workspaces_project_or_use_an_unknown_channel(): void
    {
        $other = $this->provisionTenant('Rival Co');
        $theirs = $this->asTenant($other['tenant'], fn () => Project::create(['name' => 'س']));

        $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/links', ['name' => 'a', 'project_id' => $theirs->uuid])->assertNotFound();
        $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/links', ['name' => 'a', 'channel' => 'pigeon'])->assertStatus(422)->assertJsonValidationErrors('channel');
        $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/links', ['name' => 'a', 'destination_url' => 'javascript:alert(1)'])->assertStatus(422)->assertJsonValidationErrors('destination_url');
    }

    public function test_another_workspace_cannot_see_or_change_a_link(): void
    {
        $link = $this->link();
        $other = $this->provisionTenant('Rival Co');

        $this->actingAs($other['admin'])->getJson('/api/v1/tracking/links')->assertOk()->assertJsonCount(0, 'data.links');
        $this->actingAs($other['admin'])->patchJson("/api/v1/tracking/links/{$link->uuid}", ['name' => 'x'])->assertNotFound();
    }

    public function test_a_deleted_link_stops_redirecting_but_keeps_its_history(): void
    {
        $link = $this->link();
        $this->get("/t/{$link->token}");
        $this->actingAs($this->t['admin'])->deleteJson("/api/v1/tracking/links/{$link->uuid}")->assertOk();

        $this->get("/t/{$link->token}")->assertNotFound();
        $this->assertCount(1, $this->events());
    }

    // ------------------------------------------------------------ the redirect

    public function test_a_click_is_recorded_and_the_visitor_is_sent_on_with_their_identity_and_the_utm_set(): void
    {
        $link = $this->link(['utm_source' => 'meta', 'utm_campaign' => 'oct', 'branch' => 'الرياض']);

        $response = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605 Version/17 Mobile Safari/604', 'Referer' => 'https://l.instagram.com/'])
            ->get("/t/{$link->token}?fbclid=ABC123");

        $response->assertRedirect();
        $target = $response->headers->get('Location');
        parse_str(parse_url($target, PHP_URL_QUERY), $q);

        $this->assertStringStartsWith('https://nargis.example/landing?', $target);
        $this->assertSame('meta', $q['utm_source']);
        $this->assertSame('oct', $q['utm_campaign']);
        $this->assertSame($link->token, $q['mt_t']);
        $this->assertNotNull(Visitor::clean($q['mt_vid']));
        $this->assertSame($q['mt_vid'], $response->getCookie(Visitor::COOKIE, false)->getValue());

        $event = $this->events($link)->sole();
        $this->assertSame('click', $event->event_type);
        $this->assertSame($q['mt_vid'], $event->visitor_id);
        $this->assertSame('mobile', $event->device);
        $this->assertSame('iOS', $event->os);
        $this->assertSame('ABC123', $event->click_id);
        $this->assertSame('https://l.instagram.com/', $event->referrer);
        $this->assertSame($this->project->id, $event->project_id);
        $this->assertNotNull($event->ip_hash);
        $this->assertStringNotContainsString('127.0.0.1', json_encode($event->toArray()), 'the address itself is never stored');
    }

    public function test_the_same_visitor_keeps_their_identity_across_links(): void
    {
        $a = $this->link();
        $b = $this->link(['name' => 'تيك توك', 'channel' => 'tiktok']);

        $first = $this->get("/t/{$a->token}");
        $vid = $first->getCookie(Visitor::COOKIE, false)->getValue();
        $this->withUnencryptedCookie(Visitor::COOKIE, $vid)->get("/t/{$b->token}");

        $this->assertSame([$vid, $vid], $this->events()->pluck('visitor_id')->all());
    }

    public function test_a_made_up_visitor_id_is_not_trusted(): void
    {
        $link = $this->link();

        $this->get("/t/{$link->token}?mt_vid=evil'--");

        $this->assertNotSame("evil'--", $this->events()->sole()->visitor_id);
    }

    public function test_a_whatsapp_link_and_a_phone_link_are_their_own_kind_of_click_and_are_not_decorated(): void
    {
        $wa = $this->link(['channel' => 'whatsapp', 'destination_url' => 'https://wa.me/966551234567?text=hello']);
        $tel = $this->link(['name' => 'اتصال', 'destination_url' => 'tel:+966551234567']);

        $this->get("/t/{$wa->token}")->assertRedirect('https://wa.me/966551234567?text=hello');
        $this->get("/t/{$tel->token}")->assertRedirect('tel:+966551234567');

        $this->assertSame('whatsapp_click', $this->events($wa)->sole()->event_type);
        $this->assertSame('call_click', $this->events($tel)->sole()->event_type);
    }

    public function test_an_inactive_expired_or_unknown_link_goes_nowhere(): void
    {
        $off = $this->link(['is_active' => false]);
        $old = $this->link(['expires_at' => now()->subDay()]);

        $this->get("/t/{$off->token}")->assertNotFound();
        $this->get("/t/{$old->token}")->assertNotFound();
        $this->get('/t/doesnotexist')->assertNotFound();
        $this->assertCount(0, $this->events());
    }

    // ------------------------------------------------------------ what a landing page reports

    public function test_a_landing_page_reports_what_the_visitor_does(): void
    {
        $link = $this->link(['channel' => 'landing_page']);
        $vid = Visitor::newId();

        $this->postJson("/api/v1/track/{$link->token}", ['event' => 'form_start', 'visitor_id' => $vid, 'url' => 'https://nargis.example/landing', 'utm_source' => 'meta'])->assertNoContent();
        $this->postJson("/api/v1/track/{$link->token}", ['event' => 'whatsapp_click', 'visitor_id' => $vid])->assertNoContent();

        $this->assertSame(['form_start', 'whatsapp_click'], $this->events($link)->pluck('event_type')->all());
        $this->assertSame('meta', $this->events($link)->first()->utm_source);
    }

    public function test_a_page_cannot_claim_a_sale_a_visit_to_a_site_or_a_reservation(): void
    {
        $link = $this->link();

        foreach (['won', 'reservation', 'site_visit', 'appointment_request', 'nonsense'] as $type) {
            $this->postJson("/api/v1/track/{$link->token}", ['event' => $type])->assertStatus(422);
        }

        $this->assertCount(0, $this->events());
    }

    public function test_a_replayed_beacon_is_one_event_and_an_unknown_token_says_nothing(): void
    {
        $link = $this->link();

        foreach ([1, 2, 3] as $i) {
            $this->postJson("/api/v1/track/{$link->token}", ['event' => 'visit', 'dedupe_key' => 'page-1'])->assertNoContent();
        }
        $this->postJson('/api/v1/track/unknowntok', ['event' => 'visit'])->assertNoContent();

        $this->assertCount(1, $this->events());
    }

    public function test_the_beacon_accepts_a_text_plain_body_so_no_preflight_is_needed(): void
    {
        $link = $this->link();

        $this->call('POST', "/api/v1/track/{$link->token}", [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode(['event' => 'visit', 'url' => 'https://x.test']))->assertNoContent();

        $this->assertCount(1, $this->events());
    }

    public function test_the_tracker_script_carries_the_link_and_a_dead_link_has_none(): void
    {
        $link = $this->link();

        $this->get("/tracker/{$link->token}.js")->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->assertSee("/api/v1/track/{$link->token}", false)
            ->assertSee('whatsapp_click', false);

        $this->asTenant($this->t['tenant'], fn () => $link->update(['is_active' => false]));
        $this->get("/tracker/{$link->token}.js")->assertNotFound();
    }

    // ------------------------------------------------------------ the visitor becomes a lead

    private function webhookLead(array $payload): void
    {
        $secret = 'track-secret-0123456789abcdefghij';
        $endpoint = $this->asTenant($this->t['tenant'], function () use ($secret) {
            $e = WebhookEndpoint::where('tenant_id', $this->t['tenant']->id)->first();
            $e->forceFill(['secret_hash' => Hash::make($secret)])->saveQuietly();

            return $e;
        });

        $this->withHeader('X-Webhook-Secret', $secret)->postJson("/api/v1/integrations/webhooks/{$endpoint->key}/leads", $payload)->assertSuccessful();
    }

    private function lead(string $phone = '+966551234567'): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::where('phone_normalized', $phone)->firstOrFail()->fresh());
    }

    public function test_a_form_post_with_the_visitor_id_puts_the_lead_on_the_trail_that_led_to_it(): void
    {
        $campaign = $this->asTenant($this->t['tenant'], fn () => Campaign::create(['platform' => 'meta', 'external_id' => 'c1', 'name' => 'حملة أكتوبر', 'origin' => 'manual']));
        $meta = $this->link(['campaign_id' => $campaign->id, 'utm_source' => 'meta', 'utm_campaign' => 'oct']);
        $tiktok = $this->link(['name' => 'تيك توك', 'channel' => 'tiktok', 'project_id' => null]);

        Carbon::setTestNow('2026-11-02 10:00:00');
        $vid = $this->get("/t/{$meta->token}")->getCookie(Visitor::COOKIE, false)->getValue();
        Carbon::setTestNow('2026-11-03 10:00:00');
        $this->withUnencryptedCookie(Visitor::COOKIE, $vid)->get("/t/{$tiktok->token}");

        $this->webhookLead([
            'full_name' => 'سلمان', 'phone' => '0551234567', 'visitor_id' => $vid, 'tracking_token' => $tiktok->token,
            'referrer' => 'https://tiktok.com/', 'landing_url' => 'https://nargis.example/landing', 'device' => 'mobile', 'browser' => 'Safari',
        ]);

        $lead = $this->lead();

        $this->assertSame($vid, $lead->visitor_id);
        $this->assertSame($meta->id, $lead->first_touch_link_id, 'the first link to touch them');
        $this->assertSame($tiktok->id, $lead->last_touch_link_id, 'the last link before the form');
        $this->assertSame('2026-11-02 10:00:00', $lead->first_touch_at->format('Y-m-d H:i:s'));
        $this->assertSame($this->project->id, $lead->project_id, 'the first link already said which project');
        $this->assertSame($campaign->id, $lead->campaign_ref_id);
        $this->assertSame('meta', $lead->utm_source);
        $this->assertSame('oct', $lead->utm_campaign);
        $this->assertSame('https://nargis.example/landing', $lead->landing_url);
        $this->assertSame('mobile', $lead->device);
        $this->assertSame('meta', $lead->platform);

        $mine = $this->asTenant($this->t['tenant'], fn () => TrackingEvent::where('lead_id', $lead->id)->orderBy('id')->pluck('event_type')->all());
        $this->assertSame(['click', 'click', 'form_submit', 'lead_created'], $mine, 'the earlier clicks were stitched onto the lead, the form is an event, and the lead being made is the last');
    }

    public function test_a_lead_with_no_trail_is_still_a_lead(): void
    {
        $this->webhookLead(['full_name' => 'بلا تتبع', 'phone' => '0551234567']);

        $lead = $this->lead();
        $this->assertNull($lead->first_touch_link_id);
        // Nobody touched it, so there is no click, no visit, no form: the only thing on its trail is that it was created.
        $this->assertSame(['lead_created'], $this->events()->pluck('event_type')->all());
    }

    public function test_a_forged_visitor_id_cannot_steal_anothers_events(): void
    {
        $link = $this->link();
        $vid = $this->get("/t/{$link->token}")->getCookie(Visitor::COOKIE, false)->getValue();

        // Someone else's id that we never issued to this form: nothing is stitched.
        $this->webhookLead(['full_name' => 'مزوّر', 'phone' => '0551234567', 'visitor_id' => 'v_aaaaaaaaaaaaaaaaaaaa']);

        $this->assertNull($this->lead()->first_touch_link_id);
        $click = $this->events()->firstWhere('event_type', 'click');
        $this->assertNull($click->lead_id, 'the real visitor\'s click is still unclaimed');
        $this->assertNotNull($vid);
    }

    // ------------------------------------------------------------ outcomes land on the same trail

    public function test_sales_outcomes_are_events_on_the_leads_trail_once_each(): void
    {
        $link = $this->link();
        $vid = $this->get("/t/{$link->token}")->getCookie(Visitor::COOKIE, false)->getValue();
        $this->webhookLead(['full_name' => 'سلمان', 'phone' => '0551234567', 'visitor_id' => $vid, 'tracking_token' => $link->token]);
        $lead = $this->lead();

        $appointment = $this->actingAs($this->t['admin'])->postJson('/api/v1/appointments', ['lead_id' => $lead->uuid, 'scheduled_at' => now()->addDays(2)->toIso8601String()])->assertCreated()->json('data.appointment.id');
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/appointments/{$appointment}", ['status' => 'completed'])->assertOk();
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/appointments/{$appointment}", ['status' => 'completed'])->assertOk();

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            $lead->forceFill(['deal_value_minor' => 90_000_000])->saveQuietly();
            $fresh = Lead::find($lead->id);
            $fresh->status = 'won';
            $fresh->save();
            $fresh->touch();
            $fresh->update(['city' => 'الرياض']);
        });

        $types = $this->asTenant($this->t['tenant'], fn () => TrackingEvent::where('lead_id', $lead->id)->orderBy('id')->pluck('event_type')->all());

        // The whole journey, once each: the click and the form, the lead made and its interest, the visit asked for, booked and done, the sale and what it was worth.
        $this->assertSame(['click', 'form_submit', 'lead_created', 'interested', 'appointment_request', 'appointment_booked', 'site_visit', 'won', 'revenue'], $types);
        $this->assertSame($link->id, $this->asTenant($this->t['tenant'], fn () => TrackingEvent::where('event_type', 'won')->value('tracking_link_id')));
    }

    // ------------------------------------------------------------ who gets the credit

    public function test_the_four_models_divide_one_leads_credit(): void
    {
        $this->assertSame([1 => 1.0], Attribution::weights([1], 'linear'));
        $this->assertSame([], Attribution::weights([], 'last'));

        $this->assertSame([1 => 1.0], Attribution::weights([1, 2, 3], 'first'));
        $this->assertSame([3 => 1.0], Attribution::weights([1, 2, 3], 'last'));

        $linear = Attribution::weights([1, 2, 3], 'linear');
        $this->assertEqualsWithDelta(1 / 3, $linear[2], 0.0001);

        $this->assertEqualsWithDelta(0.4, Attribution::weights([1, 2, 3, 4], 'position')[1], 0.0001);
        $this->assertEqualsWithDelta(0.1, Attribution::weights([1, 2, 3, 4], 'position')[2], 0.0001);
        $this->assertEqualsWithDelta(0.4, Attribution::weights([1, 2, 3, 4], 'position')[4], 0.0001);
        $this->assertSame([1 => 0.5, 2 => 0.5], Attribution::weights([1, 2], 'position'));

        // A link that touched twice has both shares, and the shares still sum to one.
        $twice = Attribution::weights([1, 2, 1], 'linear');
        $this->assertEqualsWithDelta(2 / 3, $twice[1], 0.0001);
        $this->assertEqualsWithDelta(1.0, array_sum(Attribution::weights([1, 2, 3, 4, 5], 'position')), 0.0001);
    }

    /** Two leads from one path (meta then tiktok), one from meta only, one with no trail. */
    private function arrangeCredit(): array
    {
        $meta = $this->link(['channel' => 'meta', 'name' => 'ميتا']);
        $tiktok = $this->link(['channel' => 'tiktok', 'name' => 'تيك توك']);
        $phones = ['+966551110001', '+966551110002', '+966551110003', '+966551110004'];

        $this->asTenant($this->t['tenant'], function () use ($meta, $tiktok, $phones) {
            $make = fn (string $phone, array $a = []) => Lead::create(['full_name' => 'عميل '.$phone, 'phone' => $phone, 'phone_normalized' => $phone, 'project_id' => $this->project->id] + $a);

            $both = $make($phones[0]);
            $metaOnly = $make($phones[1]);
            $none = $make($phones[2]);
            $won = $make($phones[3], ['status' => 'won']);
            $won->forceFill(['deal_value_minor' => 100_000_000])->saveQuietly();

            foreach ([[$both, $meta, 1], [$both, $tiktok, 2], [$metaOnly, $meta, 1], [$won, $meta, 1], [$won, $tiktok, 2]] as [$lead, $link, $hour]) {
                TrackingEvent::create(['lead_id' => $lead->id, 'tracking_link_id' => $link->id, 'event_type' => 'click', 'occurred_at' => now()->subDays(3)->setTime($hour, 0), 'visitor_id' => Visitor::newId()]);
            }

            // What attaching a lead records on the lead itself.
            $both->forceFill(['first_touch_link_id' => $meta->id, 'last_touch_link_id' => $tiktok->id])->saveQuietly();
            $metaOnly->forceFill(['first_touch_link_id' => $meta->id, 'last_touch_link_id' => $meta->id])->saveQuietly();
            $won->forceFill(['first_touch_link_id' => $meta->id, 'last_touch_link_id' => $tiktok->id])->saveQuietly();
        });

        return [$meta, $tiktok];
    }

    private function report(array $query = [])
    {
        return $this->actingAs($this->t['admin'])->getJson('/api/v1/tracking/attribution?'.http_build_query($query))->assertOk()->json('data');
    }

    public function test_first_touch_and_last_touch_credit_different_channels_for_the_same_leads(): void
    {
        $this->arrangeCredit();

        $first = collect($this->report(['model' => 'first', 'dimension' => 'channel'])['rows'])->keyBy('key');
        $last = collect($this->report(['model' => 'last', 'dimension' => 'channel'])['rows'])->keyBy('key');

        // first: both → meta, metaOnly → meta, won → meta   | last: both → tiktok, metaOnly → meta, won → tiktok
        $this->assertEquals(3.0, $first['meta']['leads']);
        $this->assertArrayNotHasKey('tiktok', $first->all());
        $this->assertEquals(1.0, $first['unattributed']['leads']);

        $this->assertEquals(1.0, $last['meta']['leads']);
        $this->assertEquals(2.0, $last['tiktok']['leads']);
    }

    public function test_credit_follows_the_lead_all_the_way_to_revenue(): void
    {
        $this->arrangeCredit();

        $first = collect($this->report(['model' => 'first'])['rows'])->keyBy('key');
        $last = collect($this->report(['model' => 'last'])['rows'])->keyBy('key');
        $linear = collect($this->report(['model' => 'linear'])['rows'])->keyBy('key');

        $this->assertEquals(1000000.0, $first['meta']['revenue'], 'the whole sale is first-touch meta');
        $this->assertEquals(1000000.0, $last['tiktok']['revenue'], 'and last-touch tiktok');
        $this->assertEquals(500000.0, $linear['meta']['revenue']);
        $this->assertEquals(500000.0, $linear['tiktok']['revenue']);
        $this->assertEquals(0.5, $linear['tiktok']['won'], 'the sale had two touches: half each');
    }

    public function test_the_unattributed_share_is_stated_and_totals_match_the_leads(): void
    {
        $this->arrangeCredit();

        $totals = $this->report(['model' => 'last'])['totals'];

        $this->assertSame(4, $totals['leads']);
        $this->assertSame(1, $totals['unattributed']);
        $this->assertEquals(25.0, $totals['unattributed_percent']);
        $this->assertEquals(4.0, $totals['leads'], 'every lead is credited exactly once whichever model');
        foreach (['first', 'last', 'linear', 'position'] as $model) {
            $this->assertEqualsWithDelta(4.0, array_sum(array_column($this->report(['model' => $model])['rows'], 'leads')), 0.05, $model);
        }
    }

    public function test_clicks_are_counted_per_bucket_and_a_link_dimension_names_each_link(): void
    {
        [$meta] = $this->arrangeCredit();

        $rows = collect($this->report(['dimension' => 'link'])['rows'])->keyBy('key');

        $this->assertSame('ميتا', $rows['link:'.$meta->id]['label']);
        $this->assertSame(3, $rows['link:'.$meta->id]['clicks']);
    }

    public function test_only_managers_see_attribution(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->actingAs($rep)->getJson('/api/v1/tracking/attribution')->assertForbidden();
        $this->actingAs($this->t['admin'])->getJson('/api/v1/tracking/attribution?model=banana')->assertStatus(422);
    }

    public function test_link_statistics_are_the_links_own_numbers(): void
    {
        [$meta] = $this->arrangeCredit();

        $link = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/tracking/links')->json('data.links'))->firstWhere('id', $meta->uuid);

        $this->assertSame(3, $link['stats']['clicks']);
        $this->assertSame(3, $link['stats']['leads'], 'leads whose first touch was this link');
        $this->assertSame(1, $link['stats']['won']);
        $this->assertEquals(1000000.0, $link['stats']['revenue']);

        $detail = $this->actingAs($this->t['admin'])->getJson("/api/v1/tracking/links/{$meta->uuid}")->assertOk();
        $detail->assertJsonPath('data.embed.link', $meta->url());
        $this->assertStringContainsString("/tracker/{$meta->token}.js", $detail->json('data.embed.script'));
        $this->assertCount(3, $detail->json('data.recent'));
    }

    public function test_the_event_vocabulary_is_closed_and_outcomes_are_server_only(): void
    {
        // Closed on purpose: every name here is one a marketer, a dataLayer and a platform all use.
        $this->assertSame([
            'landing_viewed', 'link_clicked', 'form_started', 'form_submitted', 'whatsapp_started', 'call_started', 'brochure_opened', 'project_viewed', 'property_viewed',
            'appointment_requested', 'visit_completed', 'reservation_created', 'won', 'form_step_completed', 'form_abandoned', 'lead_created', 'qualified', 'interested', 'appointment_booked', 'lost', 'revenue_recorded',
        ], array_map(fn (EventType $t) => $t->canonical(), EventType::cases()));
        $this->assertNotContains('won', EventType::browserSafeValues());
        foreach (['lead_created', 'qualified', 'interested', 'appointment_booked', 'lost', 'revenue'] as $serverOnly) {
            $this->assertNotContains($serverOnly, EventType::browserSafeValues(), "{$serverOnly} must never be claimable by a browser");
            $this->assertTrue(EventType::from($serverOnly)->isOutcome());
        }
        $this->assertTrue(EventType::Reservation->isOutcome());
        $this->assertFalse(EventType::Click->isOutcome());
    }
}
