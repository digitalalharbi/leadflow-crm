<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadSourceEvent;
use App\Models\WebhookLog;
use App\Models\WhatsappEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Forgetting the envelopes, and keeping the letters.
 *
 * Three tables store what a platform actually sent — names, phone numbers, the
 * text of a customer's WhatsApp message — so somebody can answer "why did this
 * lead arrive wrong". That question is asked within days of the lead arriving.
 * Nothing pruned them, so a workspace a year old was holding a year of its
 * customers' messages against a question nobody was going to ask.
 *
 * The distinction these tests are really about is which rows are a record and
 * which are an envelope.
 */
class PayloadRetentionTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Retention Co');
    }

    private function lead(): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => 'عميل',
            'phone_normalized' => '+96650014'.random_int(1000, 9999),
            'status' => 'new',
        ]));
    }

    private function webhookLog(int $daysAgo): WebhookLog
    {
        $log = $this->asTenant($this->t['tenant'], fn () => WebhookLog::create([
            'provider' => 'generic_webhook',
            'status' => 'accepted',
            'payload' => ['full_name' => 'سعود', 'phone' => '+966500000000'],
            'received_at' => now()->subDays($daysAgo),
        ]));

        $log->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();

        return $log;
    }

    private function whatsappEvent(int $daysAgo, string $id): WhatsappEvent
    {
        $event = $this->asTenant($this->t['tenant'], fn () => WhatsappEvent::create([
            'event_id' => $id,
            'event_type' => WhatsappEvent::TYPE_MESSAGE,
            'from_phone' => '+966500000000',
            'payload' => ['text' => 'أريد شقة في النرجس'],
        ]));

        $event->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();

        return $event;
    }

    private function sourceEvent(int $daysAgo): LeadSourceEvent
    {
        $lead = $this->lead();

        return $this->asTenant($this->t['tenant'], fn () => LeadSourceEvent::create([
            'lead_id' => $lead->id,
            'provider' => 'generic_webhook',
            'platform' => 'meta',
            'was_duplicate' => false,
            'payload' => ['full_name' => 'سعود', 'phone' => '+966500000000'],
            'received_at' => now()->subDays($daysAgo),
        ]));
    }

    public function test_an_old_webhook_body_is_removed(): void
    {
        $old = $this->webhookLog(40);
        $recent = $this->webhookLog(3);

        $this->artisan('leadflow:prune-payloads')->assertSuccessful();

        $this->assertNull(WebhookLog::withoutGlobalScopes()->find($old->id));
        $this->assertNotNull(WebhookLog::withoutGlobalScopes()->find($recent->id));
    }

    /**
     * Safe to delete outright: this table is the idempotency record for Meta's
     * retries, and Meta gives up in hours. A row a month old is guarding
     * against a duplicate that can no longer arrive.
     */
    public function test_an_old_whatsapp_event_is_removed(): void
    {
        $old = $this->whatsappEvent(40, 'wamid.old');
        $recent = $this->whatsappEvent(2, 'wamid.recent');

        $this->artisan('leadflow:prune-payloads')->assertSuccessful();

        $this->assertNull(WhatsappEvent::withoutGlobalScopes()->find($old->id));
        $this->assertNotNull(WhatsappEvent::withoutGlobalScopes()->find($recent->id));
    }

    /**
     * The row is the record and the payload is the envelope.
     *
     * The arrivals chart and the digest's "this source has gone quiet" both
     * count these rows, so deleting them would quietly rewrite a workspace's
     * own history of where its leads came from.
     */
    public function test_an_old_source_event_keeps_its_row_and_loses_its_payload(): void
    {
        $old = $this->sourceEvent(40);

        $this->artisan('leadflow:prune-payloads')->assertSuccessful();

        $fresh = LeadSourceEvent::withoutGlobalScopes()->find($old->id);

        $this->assertNotNull($fresh, 'the arrival itself is history, not an envelope');
        $this->assertNull($fresh->payload);
    }

    public function test_a_recent_source_event_keeps_its_payload(): void
    {
        $recent = $this->sourceEvent(2);

        $this->artisan('leadflow:prune-payloads')->assertSuccessful();

        $this->assertNotNull(LeadSourceEvent::withoutGlobalScopes()->find($recent->id)->payload);
    }

    /** The lead is never an envelope. */
    public function test_the_lead_itself_is_untouched(): void
    {
        $event = $this->sourceEvent(40);

        $this->artisan('leadflow:prune-payloads')->assertSuccessful();

        $this->assertNotNull(Lead::withoutGlobalScopes()->find($event->lead_id));
    }

    public function test_a_dry_run_counts_without_removing(): void
    {
        $old = $this->webhookLog(40);

        $this->artisan('leadflow:prune-payloads --dry-run')
            ->expectsOutputToContain('would prune')
            ->assertSuccessful();

        $this->assertNotNull(WebhookLog::withoutGlobalScopes()->find($old->id));
    }

    public function test_the_window_is_configurable(): void
    {
        $log = $this->webhookLog(10);

        $this->artisan('leadflow:prune-payloads --days=30')->assertSuccessful();
        $this->assertNotNull(WebhookLog::withoutGlobalScopes()->find($log->id));

        $this->artisan('leadflow:prune-payloads --days=7')->assertSuccessful();
        $this->assertNull(WebhookLog::withoutGlobalScopes()->find($log->id));
    }

    /** A retention of zero days would delete this morning's troubleshooting. */
    public function test_a_retention_below_one_day_is_refused(): void
    {
        $log = $this->webhookLog(40);

        $this->artisan('leadflow:prune-payloads --days=0')->assertFailed();

        $this->assertNotNull(WebhookLog::withoutGlobalScopes()->find($log->id));
    }

    /**
     * It runs from the scheduler, where there is no request to resolve a tenant
     * from. The tenant scope fails closed outside one, so a command that
     * forgot to lift it would prune nothing and report success.
     */
    public function test_it_prunes_every_workspace_not_just_one(): void
    {
        $other = $this->provisionTenant('Somebody Else');

        $mine = $this->webhookLog(40);
        $theirs = $this->asTenant($other['tenant'], fn () => WebhookLog::create([
            'provider' => 'generic_webhook', 'status' => 'accepted',
            'payload' => ['phone' => '+966511111111'], 'received_at' => now()->subDays(40),
        ]));
        $theirs->forceFill(['created_at' => now()->subDays(40)])->saveQuietly();

        $this->artisan('leadflow:prune-payloads')->assertSuccessful();

        $this->assertNull(WebhookLog::withoutGlobalScopes()->find($mine->id));
        $this->assertNull(WebhookLog::withoutGlobalScopes()->find($theirs->id));
    }
}
