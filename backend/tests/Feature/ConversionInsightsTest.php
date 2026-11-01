<?php

namespace Tests\Feature;

use App\Domain\Analytics\ConversionInsights;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Does any of it actually predict a sale?
 *
 * These two analyses put a behaviour on one axis and the outcome on the other,
 * so most of what can go wrong is a denominator: a bucket that quietly drops
 * the leads nobody called, a cohort too young to have converted, or a rate
 * printed as 0% where it should be nothing at all.
 */
class ConversionInsightsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private ConversionInsights $insights;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Conversion Co');
        $this->insights = app(ConversionInsights::class);
    }

    /** @param  array<string,mixed>  $attributes */
    private function lead(int $createdDaysAgo, ?int $contactAfterSeconds, bool $won, array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], function () use ($createdDaysAgo, $contactAfterSeconds, $won, $attributes) {
            $createdAt = now()->subDays($createdDaysAgo);

            $lead = Lead::create(array_merge([
                'full_name' => 'عميل',
                'phone_normalized' => '+9665007'.self::uniqueDigits(5),
                'status' => $won ? 'won' : 'new',
                'score' => 50,
            ], $attributes));

            $lead->forceFill([
                'created_at' => $createdAt,
                'first_contact_at' => $contactAfterSeconds === null
                    ? null
                    : $createdAt->copy()->addSeconds($contactAfterSeconds),
                'won_at' => $won ? $createdAt->copy()->addDays(3) : null,
            ])->saveQuietly();

            return $lead->fresh();
        });
    }

    private function speed(): array
    {
        return $this->asTenant(
            $this->t['tenant'],
            fn () => $this->insights->bySpeed(Lead::query())
        );
    }

    private function bucket(string $name): array
    {
        return collect($this->speed()['buckets'])->firstWhere('bucket', $name);
    }

    public function test_it_sorts_leads_into_the_speed_band_they_belong_in(): void
    {
        $this->lead(40, 60, false);          // a minute
        $this->lead(40, 20 * 60, false);     // twenty minutes
        $this->lead(40, 90 * 60, false);     // an hour and a half
        $this->lead(40, 10 * 3600, false);   // ten hours
        $this->lead(40, 50 * 3600, false);   // two days

        foreach (['under_5m', 'under_30m', 'under_2h', 'under_24h', 'over_24h'] as $name) {
            $this->assertSame(1, $this->bucket($name)['leads'], "bucket {$name}");
        }
    }

    /**
     * The bucket that is usually the largest and always the worst performing.
     * A chart of only the leads somebody got round to calling is a chart of
     * the team's good days.
     */
    public function test_leads_nobody_contacted_are_a_bucket_not_an_omission(): void
    {
        $this->lead(40, null, false);
        $this->lead(40, null, false);
        $this->lead(40, 60, true);

        $this->assertSame(2, $this->bucket('never')['leads']);
        $this->assertSame(0.0, $this->bucket('never')['win_rate']);
    }

    public function test_every_band_is_present_even_when_empty(): void
    {
        $this->lead(40, 60, false);

        $buckets = collect($this->speed()['buckets']);

        // A missing row reads as "no leads were that slow", which is the
        // opposite of what an absent row means.
        $this->assertSame(
            ['under_5m', 'under_30m', 'under_2h', 'under_24h', 'over_24h', 'never'],
            $buckets->pluck('bucket')->all()
        );
    }

    /** Zero would read as "none of these ever convert". That is a finding. */
    public function test_an_empty_band_has_no_rate_rather_than_a_rate_of_zero(): void
    {
        $this->lead(40, 60, false);

        $this->assertNull($this->bucket('over_24h')['win_rate']);
        $this->assertSame(0.0, $this->bucket('under_5m')['win_rate']);
    }

    public function test_it_divides_wins_by_the_leads_in_that_band(): void
    {
        $this->lead(40, 60, true);
        $this->lead(40, 120, true);
        $this->lead(40, 180, false);
        $this->lead(40, 240, false);

        $this->assertSame(4, $this->bucket('under_5m')['leads']);
        $this->assertSame(2, $this->bucket('under_5m')['won']);
        $this->assertSame(50.0, $this->bucket('under_5m')['win_rate']);
    }

    /**
     * A lead that arrived this morning has not been won yet and probably has
     * not been lost either. Counting it drags every recent cohort towards zero,
     * which makes "we got slower" and "we got newer" look identical.
     */
    public function test_leads_too_young_to_have_converted_are_left_out(): void
    {
        $this->lead(2, 60, false);
        $this->lead(40, 60, true);

        $this->assertSame(1, $this->bucket('under_5m')['leads']);
        $this->assertSame(100.0, $this->bucket('under_5m')['win_rate']);
    }

    /**
     * A first contact stamped before the lead arrived is a data defect, not an
     * instant response. Clamping it to zero would award the best bucket to the
     * worst records.
     */
    public function test_a_contact_before_the_lead_arrived_is_excluded_and_counted(): void
    {
        $lead = $this->lead(40, 60, false);
        $lead->forceFill(['first_contact_at' => $lead->created_at->copy()->subHour()])->saveQuietly();

        $speed = $this->speed();

        $this->assertSame(1, $speed['excluded']);
        $this->assertSame(0, $this->bucket('under_5m')['leads']);
        $this->assertSame(0, $this->bucket('never')['leads']);
    }

    /**
     * The one chart that can say the score is wrong. If the eighty-plus band
     * does not win more often than the twenty-minus band, the weights are
     * decoration.
     */
    public function test_the_score_bands_report_their_own_win_rate(): void
    {
        $this->lead(40, 60, true, ['score' => 90]);
        $this->lead(40, 60, false, ['score' => 85]);
        $this->lead(40, 60, false, ['score' => 10]);

        $bands = $this->asTenant(
            $this->t['tenant'],
            fn () => collect($this->insights->byScore(Lead::query())['bands'])
        );

        $this->assertSame(['80_100', '60_79', '40_59', '20_39', '0_19'], $bands->pluck('band')->all());
        $this->assertSame(50.0, $bands->firstWhere('band', '80_100')['win_rate']);
        $this->assertSame(0.0, $bands->firstWhere('band', '0_19')['win_rate']);
        $this->assertNull($bands->firstWhere('band', '40_59')['win_rate']);
    }

    /** The endpoint behind the «عوامل الإغلاق» tab. */
    public function test_the_endpoint_returns_both_analyses(): void
    {
        $this->lead(40, 60, true, ['score' => 90]);

        $data = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/reports/drivers')
            ->assertOk()
            ->json('data');

        $this->assertSame(
            ['under_5m', 'under_30m', 'under_2h', 'under_24h', 'over_24h', 'never'],
            collect($data['speed']['buckets'])->pluck('bucket')->all()
        );
        $this->assertSame(['80_100', '60_79', '40_59', '20_39', '0_19'], collect($data['score']['bands'])->pluck('band')->all());
        // Loose on type: a whole-number rate crosses JSON as 100, not 100.0.
        $this->assertEquals(100, collect($data['speed']['buckets'])->firstWhere('bucket', 'under_5m')['win_rate']);
    }

    public function test_a_sales_rep_cannot_read_the_whole_workspace(): void
    {
        $this->getJson('/api/v1/reports/drivers')->assertUnauthorized();
    }

    public function test_it_never_counts_another_workspace(): void
    {
        $this->lead(40, 60, true);

        $other = $this->provisionTenant('Somebody Else');
        $this->asTenant($other['tenant'], function () {
            $lead = Lead::create([
                'full_name' => 'عميل آخر',
                'phone_normalized' => '+966500888111',
                'status' => 'won',
                'score' => 90,
            ]);
            $lead->forceFill([
                'created_at' => now()->subDays(40),
                'first_contact_at' => now()->subDays(40)->addMinutes(2),
                'won_at' => now()->subDays(37),
            ])->saveQuietly();
        });

        $this->assertSame(1, $this->bucket('under_5m')['leads']);
    }
}
