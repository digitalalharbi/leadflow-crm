<?php

namespace Tests\Feature;

use App\Domain\Tracking\Destinations\SafeUrl;
use App\Domain\Webhooks\OutboundWebhooks;
use App\Events\LeadCreated;
use App\Events\LeadSignal;
use App\Jobs\DeliverWebhook;
use App\Models\Lead;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A company's outbound webhooks: signed, delivered once per event, its own events only, retried, and paused when the
 * endpoint keeps failing — and the secret never comes back out of the API.
 */
class OutboundWebhooksTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->provisionTenant('Alpha');
        $this->b = $this->provisionTenant('Beta');
    }

    private function subscribe(array $tenant, array $events = ['lead.created'], string $url = 'https://hooks.alpha-crm.com/mohtam'): array
    {
        return $this->actingAs($tenant['admin'])->postJson('/api/v1/webhook-subscriptions', ['name' => 'CRM', 'url' => $url, 'events' => $events])
            ->assertCreated()->json('data');
    }

    private function createLead(array $tenant, string $phone = '0551112222'): void
    {
        $this->actingAs($tenant['admin'])->postJson('/api/v1/leads', ['full_name' => 'Sara', 'phone' => $phone])->assertSuccessful();
    }

    public function test_a_new_lead_is_posted_signed_to_its_own_companys_endpoint_only(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $created = $this->subscribe($this->a);
        $this->subscribe($this->b, url: 'https://hooks.beta-crm.com/in');

        $this->createLead($this->a);

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request) use ($created) {
            [$t, $v1] = array_map(fn ($part) => explode('=', $part, 2)[1], explode(',', $request->header('X-Mohtam-Signature')[0]));
            $expected = hash_hmac('sha256', $t.'.'.$request->body(), $created['secret']);

            return $request->url() === 'https://hooks.alpha-crm.com/mohtam'
                && hash_equals($expected, $v1)
                && $request['event'] === 'lead.created'
                && $request['data']['lead']['full_name'] === 'Sara'
                && ! array_key_exists('tenant_id', $request['data']['lead']);
        });

        $this->assertSame(WebhookDelivery::SUCCEEDED, WebhookDelivery::withoutGlobalScopes()->first()->status);
    }

    public function test_the_secret_is_shown_once_and_stored_encrypted(): void
    {
        $created = $this->subscribe($this->a);

        $this->assertStringStartsWith('whsec_', $created['secret']);
        $raw = \DB::table('webhook_subscriptions')->value('secret');
        $this->assertStringNotContainsString($created['secret'], $raw);
        $this->assertStringNotContainsString($created['secret'], json_encode($this->actingAs($this->a['admin'])->getJson('/api/v1/webhook-subscriptions')->json()));
    }

    public function test_one_event_is_delivered_once_even_if_the_job_runs_again(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $this->subscribe($this->a);
        $this->createLead($this->a);

        $delivery = WebhookDelivery::withoutGlobalScopes()->first();
        (new DeliverWebhook($delivery->id))->handle(app(TenantContext::class));

        Http::assertSentCount(1);
    }

    public function test_an_endpoint_that_keeps_failing_is_retried_and_then_paused(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);
        $this->subscribe($this->a);
        $subscription = WebhookSubscription::withoutGlobalScopes()->first();
        $subscription->forceFill(['consecutive_failures' => WebhookSubscription::PAUSE_AFTER - 1])->save();

        $this->createLead($this->a);
        $delivery = WebhookDelivery::withoutGlobalScopes()->first();
        $this->assertSame('The endpoint answered 500', $delivery->error);

        // The last try gives up, counts against the subscription, and trips the breaker.
        $job = new class($delivery->id) extends DeliverWebhook
        {
            public function attempts()
            {
                return 6;
            }
        };
        $job->handle(app(TenantContext::class));

        $subscription->refresh();
        $this->assertSame(WebhookDelivery::FAILED, $delivery->fresh()->status);
        $this->assertNotNull($subscription->paused_at);
        $this->assertFalse($subscription->listensTo('lead.created'));

        // Nothing more is sent to a paused endpoint.
        Http::fake(['*' => Http::response('', 200)]);
        $this->createLead($this->a, '0553334444');
        $this->assertSame(1, WebhookDelivery::withoutGlobalScopes()->count());
    }

    public function test_private_and_plain_http_addresses_are_refused(): void
    {
        foreach (['http://hooks.alpha-crm.com/x', 'https://127.0.0.1/x', 'https://localhost/x', 'https://intranet.corp/x', 'https://hooks.alpha-crm.com:8443/x', 'https://hooks.alpha-crm.com:22/x'] as $url) {
            $this->actingAs($this->a['admin'])->postJson('/api/v1/webhook-subscriptions', ['name' => 'x', 'url' => $url, 'events' => ['lead.created']])->assertStatus(422);
        }
    }

    public function test_a_delivery_is_pinned_to_the_checked_address_and_follows_no_redirect(): void
    {
        $this->assertSame(['allow_redirects' => false], SafeUrl::pinned('https://hooks.alpha-crm.com/x'));
        $this->assertTrue(SafeUrl::isAcceptable('https://hooks.alpha-crm.com:443/x'));
        $this->assertFalse(SafeUrl::isAcceptable('https://hooks.alpha-crm.com:6379/x'));
    }

    public function test_another_company_cannot_read_change_or_replay_mine(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $mine = $this->subscribe($this->a);
        $this->createLead($this->a);
        $delivery = WebhookDelivery::withoutGlobalScopes()->first();
        $id = $mine['subscription']['id'];

        $this->actingAs($this->b['admin'])->getJson("/api/v1/webhook-subscriptions/{$id}/deliveries")->assertNotFound();
        $this->actingAs($this->b['admin'])->patchJson("/api/v1/webhook-subscriptions/{$id}", ['url' => 'https://evil.example-attacker.com/x'])->assertNotFound();
        $this->actingAs($this->b['admin'])->postJson("/api/v1/webhook-deliveries/{$delivery->uuid}/replay")->assertNotFound();
        $this->assertSame([], $this->actingAs($this->b['admin'])->getJson('/api/v1/webhook-subscriptions')->json('data'));
    }

    public function test_a_sales_rep_cannot_manage_webhooks(): void
    {
        $rep = $this->makeUser($this->a['tenant']);

        $this->actingAs($rep)->postJson('/api/v1/webhook-subscriptions', ['name' => 'x', 'url' => 'https://hooks.alpha-crm.com/x', 'events' => ['lead.created']])->assertForbidden();
        $this->actingAs($rep)->getJson('/api/v1/webhook-subscriptions')->assertForbidden();
    }

    public function test_replay_sends_the_same_event_id_again_and_the_test_button_reports_the_result(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $created = $this->subscribe($this->a);
        $this->createLead($this->a);
        $delivery = WebhookDelivery::withoutGlobalScopes()->first();

        $this->actingAs($this->a['admin'])->postJson("/api/v1/webhook-deliveries/{$delivery->uuid}/replay")->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r->header('X-Mohtam-Event-Id')[0] === $delivery->event_id);

        $this->actingAs($this->a['admin'])->postJson("/api/v1/webhook-subscriptions/{$created['subscription']['id']}/test")
            ->assertOk()->assertJsonPath('data.status', WebhookDelivery::SUCCEEDED)->assertJsonPath('data.event', 'webhook.test');
    }

    public function test_internal_events_map_to_the_published_names(): void
    {
        $lead = $this->asTenant($this->a['tenant'], fn () => Lead::create(['full_name' => 'X', 'phone' => '0550000000']));
        $map = app(OutboundWebhooks::class);

        $this->assertSame('lead.created', $map->nameFor(new LeadCreated($lead)));
        $this->assertSame('lead.interested', $map->nameFor(new LeadSignal($lead, 'lead.interest_changed', ['from' => 'warm', 'to' => 'hot'])));
        $this->assertNull($map->nameFor(new LeadSignal($lead, 'lead.interest_changed', ['from' => 'hot', 'to' => 'visit_ready'])));
        $this->assertSame('reservation.created', $map->nameFor(new LeadSignal($lead, 'lead.reservation_created', ['reservation' => 'r'])));
    }
}
