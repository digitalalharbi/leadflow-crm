<?php

namespace Tests\Feature;

use App\Models\DestinationDelivery;
use App\Models\TrackingDestination;
use App\Models\VisitorSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the tracking hub remembers about a visitor, and for how long.
 *
 * A delivery log is a debugging record ("why did Meta not get this?") asked within days; a visitor session is the trail that
 * credited a lead, whose result the lead keeps. Past the window the log goes and the session is stripped of what could point at a
 * person — the lead's own credit is untouched, because that is the business's record.
 */
class HubRetentionTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Hub Retention Co');
    }

    private function delivery(int $daysAgo, string $eventId): DestinationDelivery
    {
        return $this->asTenant($this->t['tenant'], function () use ($daysAgo, $eventId) {
            $destination = TrackingDestination::create(['kind' => 'webhook', 'name' => 'w', 'is_enabled' => true, 'config' => [], 'send_value' => true, 'hash_identifiers' => true, 'consent_mode' => 'required']);
            $delivery = DestinationDelivery::create(['destination_id' => $destination->id, 'event_id' => $eventId, 'event_name' => 'form_submitted', 'status' => 'sent', 'request_summary' => ['value' => 100]]);
            $delivery->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();

            return $delivery;
        });
    }

    private function visitorTrail(int $daysAgo, string $id): VisitorSession
    {
        return $this->asTenant($this->t['tenant'], fn () => VisitorSession::create([
            'visitor_id' => 'v'.$id, 'session_id' => $id, 'first_touch' => ['utm' => ['source' => 'meta'], 'click_ids' => ['fbclid' => 'abc']], 'last_touch' => ['utm' => ['source' => 'meta']],
            'device' => 'mobile', 'browser' => 'Safari', 'os' => 'iOS', 'ip_hash' => str_repeat('a', 64), 'consent' => 'granted',
            'started_at' => now()->subDays($daysAgo), 'last_seen_at' => now()->subDays($daysAgo),
        ]));
    }

    public function test_an_old_delivery_log_is_removed_and_a_recent_one_kept(): void
    {
        $old = $this->delivery(120, 'evt-old');
        $recent = $this->delivery(3, 'evt-new');

        $this->artisan('leadflow:prune-payloads')->assertSuccessful();

        $this->assertNull(DestinationDelivery::withoutGlobalScopes()->find($old->id));
        $this->assertNotNull(DestinationDelivery::withoutGlobalScopes()->find($recent->id));
    }

    public function test_an_old_session_keeps_its_row_but_loses_what_could_point_at_a_person(): void
    {
        $old = $this->visitorTrail(400, 'sold');
        $recent = $this->visitorTrail(5, 'snew');

        $this->artisan('leadflow:prune-payloads')->assertSuccessful();

        $old = VisitorSession::withoutGlobalScopes()->find($old->id);
        $this->assertNotNull($old, 'The row stays: a lead may point at it.');
        $this->assertNull($old->ip_hash);
        $this->assertNull($old->browser);
        $this->assertNull($old->first_touch['click_ids'] ?? null);
        $this->assertSame('meta', $old->first_touch['utm']['source'] ?? null, 'The credit — which source — stays; only the identifiers go.');

        $recent = VisitorSession::withoutGlobalScopes()->find($recent->id);
        $this->assertSame(str_repeat('a', 64), $recent->ip_hash);
        $this->assertSame('abc', $recent->first_touch['click_ids']['fbclid']);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $old = $this->delivery(120, 'evt-old');
        $session = $this->visitorTrail(400, 'sold');

        $this->artisan('leadflow:prune-payloads --dry-run')->assertSuccessful();

        $this->assertNotNull(DestinationDelivery::withoutGlobalScopes()->find($old->id));
        $this->assertNotNull(VisitorSession::withoutGlobalScopes()->find($session->id)->ip_hash);
    }
}
