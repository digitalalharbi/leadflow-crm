<?php

namespace Tests\Feature;

use App\Models\ConversionExport;
use App\Models\Lead;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What is quietly wrong with the data a workspace is paying to collect.
 *
 * The assertion repeated throughout is that every count agrees with the list it
 * opens. A count whose list disagrees with it is worse than no count at all,
 * because somebody will trust one of the two and have no way to tell which.
 */
class DataQualityTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Quality Co');
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل '.random_int(1000, 9999),
            'phone_normalized' => '+9665004'.random_int(10000, 99999),
            'status' => 'new',
            'platform' => 'meta',
            'source' => 'paid_social',
            'campaign_name' => 'حملة',
            'assigned_to' => $this->t['admin']->id,
        ], $attributes)));
    }

    private function checks(): array
    {
        $data = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/data-quality')->assertOk()->json('data');

        return collect($data['checks'])->keyBy('key')->all();
    }

    /** The number and the list behind it have to be the same leads. */
    private function assertCountMatchesList(string $key): void
    {
        $check = $this->checks()[$key];

        $listed = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/leads?'.http_build_query($check['filter'] + ['per_page' => 100]))
            ->assertOk()->json('meta.total');

        $this->assertSame($check['count'], $listed, "the {$key} count and its list disagree");
    }

    public function test_an_unreadable_phone_is_counted_and_listed(): void
    {
        $this->lead();
        $this->lead(['phone_normalized' => null, 'phone' => 'لا يوجد']);

        $this->assertSame(1, $this->checks()['unreachable_phones']['count']);
        $this->assertCountMatchesList('unreachable_phones');
    }

    /** An invalid lead is already known to be unreachable; counting it again is noise. */
    public function test_a_lead_already_marked_invalid_is_not_counted_again(): void
    {
        $this->lead(['phone_normalized' => null, 'status' => 'invalid']);

        $this->assertSame(0, $this->checks()['unreachable_phones']['count']);
    }

    /**
     * Counted as the extra rows, not the groups: a workspace reading this wants
     * to know how many records to merge.
     */
    public function test_duplicates_count_the_extra_rows(): void
    {
        $this->lead(['email' => 'one@buyer.test']);
        $this->lead(['email' => 'one@buyer.test']);
        $this->lead(['email' => 'one@buyer.test']);
        $this->lead(['email' => 'other@buyer.test']);

        // All three rows, because merging them means opening all three — the
        // count and the list it opens have to be the same leads.
        $this->assertSame(3, $this->checks()['duplicates']['count']);
        $this->assertCountMatchesList('duplicates');
    }

    /**
     * Phone duplicates are checked by email because they cannot exist: the
     * leads table carries a unique constraint on (tenant, normalised phone).
     * A check that reported zero forever would imply the workspace had been
     * checked for something it had not.
     */
    public function test_a_duplicate_phone_cannot_be_inserted_at_all(): void
    {
        $this->lead(['phone_normalized' => '+966500111222']);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->lead(['phone_normalized' => '+966500111222']);
    }

    public function test_missing_attribution_is_counted_and_listed(): void
    {
        $this->lead();
        $this->lead(['platform' => null, 'source' => null, 'campaign_name' => null]);

        $this->assertSame(1, $this->checks()['missing_attribution']['count']);
        $this->assertCountMatchesList('missing_attribution');
    }

    /**
     * Narrower than missing attribution on purpose: the platform is known, so
     * the money is traceable in the ad account but not to anything in here.
     */
    public function test_a_paid_lead_with_no_campaign_is_counted_separately(): void
    {
        $this->lead();
        $this->lead(['campaign_name' => null, 'campaign_id' => null]);

        $this->assertSame(1, $this->checks()['missing_campaign']['count']);
        $this->assertCountMatchesList('missing_campaign');
    }

    public function test_unassigned_open_leads_are_counted_and_listed(): void
    {
        $this->lead();
        $this->lead(['assigned_to' => null]);
        // Closed and unassigned is not work waiting for anybody.
        $this->lead(['assigned_to' => null, 'status' => 'won']);

        $this->assertSame(1, $this->checks()['unassigned']['count']);
        $this->assertCountMatchesList('unassigned');
    }

    public function test_a_stale_lead_is_counted_and_listed(): void
    {
        $this->lead();

        $old = $this->lead();
        $old->forceFill([
            'created_at' => now()->subDays(40),
            'last_contact_at' => now()->subDays(30),
        ])->saveQuietly();

        $this->assertSame(1, $this->checks()['stale']['count']);
        $this->assertCountMatchesList('stale');
    }

    /**
     * A won deal with nothing the platform can match on. The upload would
     * succeed and teach the optimiser nothing; this screen is where a
     * workspace finds out that happened.
     */
    public function test_a_sale_that_cannot_be_credited_is_counted_and_listed(): void
    {
        $lead = $this->lead([
            'phone_normalized' => null, 'email' => null,
            'click_id' => null, 'provider_lead_id' => null,
        ]);

        $this->asTenant($this->t['tenant'], fn () => ConversionExport::create([
            'lead_id' => $lead->id,
            'platform' => 'meta',
            'event' => 'won',
            'occurred_at' => now(),
            'status' => ConversionExport::UNMATCHABLE,
        ]));

        $this->assertSame(1, $this->checks()['unmatchable_conversions']['count']);
        $this->assertCountMatchesList('unmatchable_conversions');
    }

    public function test_a_clean_workspace_reports_nothing_to_fix(): void
    {
        $this->lead();
        $this->lead();

        $data = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/data-quality')->assertOk()->json('data');

        $this->assertSame(0, $data['total_issues']);
    }

    /** Another workspace's problems are not this workspace's problems. */
    public function test_counts_never_cross_tenants(): void
    {
        $other = $this->provisionTenant('Elsewhere Co');

        $this->asTenant($other['tenant'], fn () => Lead::create([
            'full_name' => 'سر', 'phone_normalized' => null, 'status' => 'new',
        ]));

        $this->assertSame(0, $this->checks()['unreachable_phones']['count']);
    }

    public function test_a_sales_rep_cannot_see_workspace_wide_quality(): void
    {
        $this->actingAs($this->makeUser($this->t['tenant']))
            ->getJson('/api/v1/data-quality')
            ->assertForbidden();
    }

    /** A broken connection does not announce itself; leads just stop arriving. */
    public function test_integration_health_is_reported(): void
    {
        $data = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/data-quality')->assertOk()->json('data');

        $this->assertIsArray($data['integrations']);
    }

    /**
     * A count on its own is not a finding.
     *
     * Ten leads with no campaign is a quarter of a small workspace and a
     * rounding error in a large one, and the screen showed the same "10"
     * either way. The share is what says whether it is worth an afternoon,
     * and the share needs a denominator the client did not have.
     */
    public function test_it_reports_the_denominator_the_counts_are_out_of(): void
    {
        $data = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/data-quality')
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('total_leads', $data);
        $this->assertSame(
            $this->asTenant($this->t['tenant'], fn () => Lead::count()),
            $data['total_leads'],
        );
    }
}
