<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadSourceEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Whether leads are still arriving, and how many of them are the same lead.
 *
 * The failure this exists to catch is silent: a form stops posting, the
 * integration still shows a green badge and a valid secret, and the spend
 * carries on for eleven days. So most of these tests are about the empty and
 * the repeated cases rather than the healthy one.
 */
class IngestionInsightsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Ingestion Co');
    }

    private function arrive(string $provider, bool $duplicate = false, int $daysAgo = 0, ?string $platform = 'meta'): LeadSourceEvent
    {
        return $this->asTenant($this->t['tenant'], function () use ($provider, $duplicate, $daysAgo, $platform) {
            $lead = Lead::create([
                'full_name' => 'عميل',
                'phone_normalized' => '+9665009'.random_int(10000, 99999),
                'status' => 'new',
            ]);

            return LeadSourceEvent::create([
                'lead_id' => $lead->id,
                'provider' => $provider,
                'platform' => $platform,
                'was_duplicate' => $duplicate,
                'received_at' => now()->subDays($daysAgo),
            ]);
        });
    }

    private function insights(int $days = 30): array
    {
        return $this->actingAs($this->t['admin'])
            ->getJson("/api/v1/integrations/ingestion?days={$days}")
            ->assertOk()
            ->json('data');
    }

    public function test_it_returns_a_point_for_every_day_including_the_silent_ones(): void
    {
        $this->arrive('meta_lead_ads');
        $this->arrive('meta_lead_ads', daysAgo: 5);

        $data = $this->insights();

        // A series that skips silent days draws a straight line across an
        // outage, which is the one thing this chart exists to show.
        $this->assertCount(30, $data['daily']);
        $this->assertSame(now()->toDateString(), end($data['daily'])['date']);

        $quiet = collect($data['daily'])->firstWhere('date', now()->subDays(2)->toDateString());
        $this->assertSame(0, $quiet['accepted']);
        $this->assertSame(0, $quiet['duplicates']);
    }

    public function test_duplicates_are_plotted_beside_arrivals_not_netted_out(): void
    {
        $this->arrive('generic_webhook');
        $this->arrive('generic_webhook', duplicate: true);
        $this->arrive('generic_webhook', duplicate: true);

        $today = collect($this->insights()['daily'])->firstWhere('date', now()->toDateString());

        /*
         * A day of sixty events where fifty were duplicates is a broken
         * integration posting the same payload repeatedly. Netted out, it
         * reads exactly like a good day.
         */
        $this->assertSame(1, $today['accepted']);
        $this->assertSame(2, $today['duplicates']);
    }

    public function test_the_duplicate_rate_is_null_rather_than_zero_when_nothing_arrived(): void
    {
        $summary = $this->insights()['summary'];

        // "0% duplicates" on a dead integration reads as perfect health.
        $this->assertSame(0, $summary['total']);
        $this->assertNull($summary['duplicate_rate']);
    }

    public function test_the_summary_counts_every_event_and_rates_the_repeats(): void
    {
        $this->arrive('meta_lead_ads');
        $this->arrive('meta_lead_ads');
        $this->arrive('meta_lead_ads');
        $this->arrive('meta_lead_ads', duplicate: true);

        $summary = $this->insights()['summary'];

        $this->assertSame(4, $summary['total']);
        $this->assertSame(3, $summary['accepted']);
        $this->assertSame(1, $summary['duplicates']);
        // Loose on type: a whole-number rate crosses JSON as 25, not 25.0.
        $this->assertEquals(25, $summary['duplicate_rate']);
    }

    public function test_the_duplicate_rate_keeps_one_decimal(): void
    {
        foreach (range(1, 3) as $i) {
            $this->arrive('meta_lead_ads');
        }
        $this->arrive('meta_lead_ads', duplicate: true);
        $this->arrive('meta_lead_ads', duplicate: true);
        $this->arrive('meta_lead_ads', duplicate: true);
        $this->arrive('meta_lead_ads', duplicate: true);

        // 4 of 7 — a third of a percent either way is not worth printing, but
        // 57 and 57.1 are different enough to notice.
        $this->assertEquals(57.1, $this->insights()['summary']['duplicate_rate']);
    }

    public function test_each_source_reports_when_it_was_last_heard_from(): void
    {
        $this->arrive('meta_lead_ads', daysAgo: 9);
        $this->arrive('generic_webhook', daysAgo: 0);

        $rows = collect($this->insights()['by_provider']);

        /*
         * The column a reader acts on. A source with a healthy total and
         * nothing for nine days is the broken one, and totals alone cannot
         * say so.
         */
        $stale = $rows->firstWhere('provider', 'meta_lead_ads');
        $this->assertNotNull($stale['last_received_at']);
        $this->assertStringContainsString(now()->subDays(9)->toDateString(), $stale['last_received_at']);
    }

    /**
     * JavaScript parses a timestamp with no offset as *local* time. Against a
     * UTC database and a browser in Asia/Riyadh that shifted every reading by
     * three hours: a source that delivered a minute ago reported as "آخر عميل
     * منذ 3 ساعات", and an outage three hours shorter than it really was.
     */
    public function test_the_last_arrival_carries_its_timezone(): void
    {
        $this->arrive('meta_lead_ads');

        $last = collect($this->insights()['by_provider'])->first()['last_received_at'];

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/',
            $last,
            'A naked Y-m-d H:i:s is read as local time by the browser.'
        );
    }

    public function test_events_outside_the_window_are_left_out(): void
    {
        $this->arrive('meta_lead_ads', daysAgo: 45);

        $data = $this->insights(30);

        $this->assertSame(0, $data['summary']['total']);
        $this->assertSame([], $data['by_provider']);
    }

    /** The whole product is multi-tenant, so every aggregate is too. */
    public function test_it_never_counts_another_workspace(): void
    {
        $this->arrive('meta_lead_ads');

        $other = $this->provisionTenant('Somebody Else');
        $this->asTenant($other['tenant'], function () {
            $lead = Lead::create([
                'full_name' => 'عميل آخر',
                'phone_normalized' => '+966500777111',
                'status' => 'new',
            ]);

            LeadSourceEvent::create([
                'lead_id' => $lead->id,
                'provider' => 'generic_webhook',
                'was_duplicate' => false,
                'received_at' => now(),
            ]);
        });

        $data = $this->insights();

        $this->assertSame(1, $data['summary']['total']);
        $this->assertSame(['meta_lead_ads'], collect($data['by_provider'])->pluck('provider')->all());
    }

    public function test_a_sales_rep_may_read_it_but_a_stranger_may_not(): void
    {
        $this->getJson('/api/v1/integrations/ingestion')->assertUnauthorized();
    }
}
