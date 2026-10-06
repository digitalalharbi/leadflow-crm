<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadSourceEvent;
use App\Services\Integrations\LeadPuller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Going and asking a source what it has.
 *
 * Five providers implemented `fetch()` and nothing in the product called any of
 * them, so a workspace with a valid TikTok token had a green badge and no
 * leads. These tests are mostly about the states around a poll rather than the
 * happy path: what a failed poll must not claim, and what a repeated poll must
 * not record twice.
 */
class LeadPullerTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private LeadPuller $puller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Pulling Co');
        $this->puller = app(LeadPuller::class);
    }

    private function integration(string $provider = 'tiktok', array $credentials = ['access_token' => 'tok', 'advertiser_id' => 'adv-1']): Integration
    {
        return $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => $provider,
            'name' => 'مصدر الاختبار',
            'status' => 'connected',
            'is_active' => true,
            'credentials' => $credentials,
            'config' => ['page_id' => 'page-1'],
        ]));
    }

    /** @param  array<int,array<string,mixed>>  $rows */
    private function tiktokReturns(array $rows): void
    {
        Http::fake([
            'business-api.tiktok.com/*' => Http::response(['data' => ['list' => $rows]]),
        ]);
    }

    private function pull(Integration $integration): array
    {
        return $this->puller->pull($this->t['tenant'], $integration);
    }

    public function test_it_fetches_rows_and_creates_leads(): void
    {
        $integration = $this->integration();
        $this->tiktokReturns([
            ['lead_id' => 'tt-1', 'name' => 'نورة الشمري', 'phone_number' => '+966500111222'],
            ['lead_id' => 'tt-2', 'name' => 'سعد الحربي', 'phone_number' => '0500111333'],
        ]);

        $result = $this->pull($integration);

        $this->assertSame(2, $result['fetched']);
        $this->assertSame(2, $result['created']);
        $this->assertSame(2, Lead::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->count());
    }

    /**
     * A poll every five minutes re-reads the same window. Without this, every
     * lead would log a fresh duplicate event on every run, and the duplicate
     * rate on the arrivals chart — which exists to expose a source posting the
     * same payload twice — would be dominated by our own polling.
     */
    public function test_a_row_already_taken_is_skipped_not_recorded_as_a_duplicate(): void
    {
        $integration = $this->integration();
        $this->tiktokReturns([
            ['lead_id' => 'tt-1', 'name' => 'نورة الشمري', 'phone_number' => '+966500111222'],
        ]);

        $this->pull($integration);
        $second = $this->pull($integration);

        $this->assertSame(1, $second['skipped']);
        $this->assertSame(0, $second['duplicates']);
        $this->assertSame(0, $second['created']);

        // One lead, and exactly one arrival event.
        $this->assertSame(1, Lead::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->count());
        $this->assertSame(1, LeadSourceEvent::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->count());
    }

    /**
     * `last_synced_at` means "the last time leads actually came through". A
     * source that has been refusing our token for a week must not read as
     * synced a minute ago.
     */
    public function test_a_failed_poll_records_the_error_and_does_not_claim_a_sync(): void
    {
        $integration = $this->integration();
        Http::fake(['business-api.tiktok.com/*' => Http::response('nope', 500)]);

        $result = $this->pull($integration);
        $integration->refresh();

        $this->assertNotNull($result['error']);
        $this->assertNull($integration->last_synced_at);
        $this->assertStringContainsString('TikTok', (string) $integration->last_error);
    }

    public function test_a_successful_poll_stamps_the_sync_and_clears_the_last_error(): void
    {
        $integration = $this->integration();
        $integration->forceFill(['last_error' => 'something old'])->save();
        $this->tiktokReturns([]);

        $this->pull($integration);
        $integration->refresh();

        $this->assertNotNull($integration->last_synced_at);
        $this->assertNull($integration->last_error);
    }

    /** A webhook source is working precisely because nobody polls it. */
    public function test_a_push_only_provider_is_left_alone(): void
    {
        Http::fake();
        $integration = $this->integration('generic_webhook', []);

        $result = $this->pull($integration);

        $this->assertSame(0, $result['fetched']);
        $this->assertNull($integration->fresh()->last_synced_at);
        Http::assertNothingSent();
    }

    public function test_a_provider_without_credentials_is_not_called(): void
    {
        Http::fake();
        $integration = $this->integration('tiktok', []);

        $this->pull($integration);

        Http::assertNothingSent();
    }

    /**
     * WhatsApp shares the integrations table because a workspace configures it
     * on the same screen, but it carries messages rather than leads and has no
     * provider class. Walking the table without checking threw on the first
     * workspace that used it — which the very first run of the command did.
     */
    public function test_an_integration_that_is_not_a_lead_source_is_ignored(): void
    {
        Http::fake();
        $integration = $this->integration('whatsapp', ['access_token' => 'x']);

        $result = $this->pull($integration);

        $this->assertSame(0, $result['fetched']);
        $this->assertNull($result['error']);
    }

    /** One malformed row must not cost the rest of the batch. */
    public function test_a_row_without_a_phone_is_rejected_and_the_others_still_land(): void
    {
        $integration = $this->integration();
        $this->tiktokReturns([
            ['lead_id' => 'tt-1', 'name' => 'بلا رقم'],
            ['lead_id' => 'tt-2', 'name' => 'سعد الحربي', 'phone_number' => '+966500111444'],
        ]);

        $result = $this->pull($integration);

        $this->assertSame(2, $result['fetched']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['rejected'] + $result['failed']);
    }

    /**
     * The endpoint behind the "sync now" button.
     *
     * Written after the button returned a 500 in the browser: the controller
     * reached for a `$context` property it did not have. Nothing caught it
     * because the action had no test — the puller underneath was covered nine
     * ways and the thing a user actually presses was covered none.
     */
    public function test_the_sync_endpoint_runs_a_poll_and_reports_what_it_did(): void
    {
        $integration = $this->integration();
        $this->tiktokReturns([
            ['lead_id' => 'tt-9', 'name' => 'ليلى القحطاني', 'phone_number' => '+966500222333'],
        ]);

        $data = $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/integrations/{$integration->uuid}/sync")
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['result']['fetched']);
        $this->assertSame(1, $data['result']['created']);
        $this->assertNull($data['result']['error']);
        $this->assertNotNull($data['integration']['last_synced_at']);
    }

    public function test_the_sync_endpoint_reports_a_source_it_could_not_reach(): void
    {
        $integration = $this->integration();
        Http::fake(['business-api.tiktok.com/*' => Http::response('nope', 500)]);

        $data = $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/integrations/{$integration->uuid}/sync")
            ->assertOk()
            ->json('data');

        // The provider's own words. "Could not connect" sends an admin to their
        // network; "TikTok Business API error: 500" sends them to the one place
        // that can fix it.
        $this->assertNotNull($data['result']['error']);
        $this->assertNull($data['integration']['last_synced_at']);
    }

    public function test_a_stranger_cannot_make_us_call_a_source(): void
    {
        $integration = $this->integration();

        $this->postJson("/api/v1/integrations/{$integration->uuid}/sync")->assertUnauthorized();
    }

    public function test_it_never_writes_into_another_workspace(): void
    {
        $integration = $this->integration();
        $this->tiktokReturns([
            ['lead_id' => 'tt-1', 'name' => 'نورة الشمري', 'phone_number' => '+966500111222'],
        ]);

        $other = $this->provisionTenant('Somebody Else');

        $this->pull($integration);

        $this->assertSame(1, Lead::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->count());
        $this->assertSame(0, Lead::withoutGlobalScopes()->where('tenant_id', $other['tenant']->id)->count());
    }
}
