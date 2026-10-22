<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ad;
use App\Models\Campaign;
use App\Models\Creative;
use App\Models\Lead;
use App\Models\Partner;
use App\Models\Project;
use App\Models\TrackingLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The attribution explorer: the same credit question asked of any slice (a campaign, a platform, an
 * ad, a creative, a partner, a rep, a project) and under all four models at once.
 */
class AttributionExplorerTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Campaign $campaignA;

    private Campaign $campaignB;

    private TrackingLink $meta;

    private TrackingLink $google;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Explorer Co');

        $this->asTenant($this->t['tenant'], function () {
            $this->campaignA = Campaign::create(['platform' => 'meta', 'external_id' => 'a', 'name' => 'حملة أ', 'origin' => 'manual']);
            $this->campaignB = Campaign::create(['platform' => 'google_ads', 'external_id' => 'b', 'name' => 'حملة ب', 'origin' => 'manual']);
            $this->meta = TrackingLink::create(['token' => 'metaaaaa', 'name' => 'ميتا', 'channel' => 'meta', 'campaign_id' => $this->campaignA->id]);
            $this->google = TrackingLink::create(['token' => 'googleee', 'name' => 'جوجل', 'channel' => 'google', 'campaign_id' => $this->campaignB->id]);
        });
    }

    /** @param  array<string,mixed>  $attributes */
    private function lead(?TrackingLink $first, ?TrackingLink $last, array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], function () use ($first, $last, $attributes) {
            $lead = Lead::create(['full_name' => 'عميل', 'phone_normalized' => '+9665'.random_int(10000000, 99999999), 'status' => 'contacted', 'first_contact_at' => now(), 'last_contact_at' => now()]);
            DB::table('leads')->where('id', $lead->id)->update($attributes + ['first_touch_link_id' => $first?->id, 'last_touch_link_id' => $last?->id, 'created_at' => now()->subDay()]);

            return $lead->fresh();
        });
    }

    private function api(string $path, array $query = [])
    {
        return $this->actingAs($this->t['admin'])->getJson("/api/v1/tracking/attribution{$path}?".http_build_query($query));
    }

    public function test_the_same_lead_is_credited_differently_by_each_model_and_the_swing_says_how_much(): void
    {
        // One lead found us through Meta and bought after a Google click; one only ever touched Meta.
        $this->lead($this->meta, $this->google, ['status' => 'won', 'deal_value_minor' => 100_000_000]);
        $this->lead($this->meta, $this->meta);

        $data = $this->api('/compare', ['dimension' => 'channel'])->assertOk()->json('data');
        $rows = collect($data['rows'])->keyBy('key');

        $this->assertSame(['first', 'last', 'linear', 'position'], $data['models']);
        $this->assertEquals(2, $rows['meta']['by_model']['first']['leads']);
        $this->assertEquals(0, $rows['google']['by_model']['first']['leads'], 'first touch never credits the buying click');
        $this->assertEquals(1, $rows['google']['by_model']['last']['leads']);
        $this->assertEquals(0.5, $rows['google']['by_model']['linear']['leads']);
        $this->assertEquals(1000000, $rows['google']['by_model']['last']['revenue']);
        $this->assertEquals(0, $rows['google']['by_model']['first']['revenue']);
        $this->assertGreaterThan(0, $rows['google']['swing']['revenue'], 'google’s credit depends on who is counting');
        $this->assertEquals(100, $rows['google']['swing']['revenue']);

        // The totals under every model account for the same two leads.
        foreach ($data['totals'] as $model => $totals) {
            $this->assertEquals(2, $totals['leads'], $model);
        }
    }

    public function test_the_slice_can_be_a_campaign_a_platform_a_rep_a_partner_an_ad_or_a_creative(): void
    {
        $partner = $this->asTenant($this->t['tenant'], fn () => Partner::create(['name' => 'وسيط', 'type' => 'broker']));
        $creative = $this->asTenant($this->t['tenant'], fn () => Creative::create(['platform' => 'meta', 'external_id' => 'cr1', 'format' => 'image', 'headline' => 'شقق']));
        $ad = $this->asTenant($this->t['tenant'], fn () => Ad::create(['campaign_id' => $this->campaignA->id, 'creative_id' => $creative->id, 'external_id' => 'ad1', 'name' => 'إعلان', 'status' => 'active']));

        $this->lead($this->meta, $this->meta, ['campaign_ref_id' => $this->campaignA->id, 'platform' => 'meta', 'ad_ref_id' => $ad->id, 'assigned_to' => $this->t['admin']->id]);
        $this->lead($this->google, $this->google, ['campaign_ref_id' => $this->campaignB->id, 'platform' => 'google_ads', 'partner_id' => $partner->id]);
        $this->lead($this->google, $this->google, ['campaign_ref_id' => $this->campaignB->id, 'platform' => 'google_ads']);

        $total = fn (array $q) => (int) $this->api('', $q + ['dimension' => 'channel'])->assertOk()->json('data.totals.leads');

        $this->assertSame(3, $total([]));
        $this->assertSame(1, $total(['campaign_id' => $this->campaignA->uuid]));
        $this->assertSame(2, $total(['campaign_id' => $this->campaignB->uuid]));
        $this->assertSame(2, $total(['platform' => 'google_ads']));
        $this->assertSame(1, $total(['partner_id' => $partner->uuid]));
        $this->assertSame(1, $total(['ad_id' => $ad->uuid]));
        $this->assertSame(1, $total(['creative_id' => $creative->uuid]));
        $this->assertSame(1, $total(['rep_id' => $this->t['admin']->uuid]));
        $this->assertSame(0, $total(['platform' => 'meta', 'campaign_id' => $this->campaignB->uuid]), 'filters narrow together');

        // The comparison takes the same slices.
        $this->assertEquals(2, $this->api('/compare', ['platform' => 'google_ads'])->json('data.totals.last.leads'));
    }

    public function test_a_slice_that_is_not_ours_is_not_found(): void
    {
        $this->api('', ['campaign_id' => (string) Str::uuid()])->assertNotFound();

        $other = $this->provisionTenant('Else');
        $foreign = $this->asTenant($other['tenant'], fn () => Campaign::create(['platform' => 'meta', 'external_id' => 'z', 'name' => 'غريبة', 'origin' => 'manual']));
        $this->api('', ['campaign_id' => $foreign->uuid])->assertNotFound();
        $this->api('/compare', ['rep_id' => $other['admin']->uuid])->assertNotFound();

        $this->api('', ['campaign_id' => 'nope'])->assertStatus(422);
        $this->api('/compare', ['dimension' => 'banana'])->assertStatus(422);
    }

    public function test_the_filter_lists_are_the_workspaces_own_and_for_managers_only(): void
    {
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'م']));
        $this->lead($this->meta, $this->meta, ['platform' => 'meta']);

        $options = $this->api('/options')->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(['حملة أ', 'حملة ب'], array_column($options['campaigns'], 'name'));
        $this->assertSame(['meta'], $options['platforms']);
        $this->assertContains($this->t['admin']->uuid, array_column($options['reps'], 'id'));
        $this->assertNotNull($project);

        $other = $this->provisionTenant('Else');
        $this->assertSame([], $this->actingAs($other['admin'])->getJson('/api/v1/tracking/attribution/options')->json('data.campaigns'));

        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $this->actingAs($rep)->getJson('/api/v1/tracking/attribution/options')->assertForbidden();
        $this->actingAs($rep)->getJson('/api/v1/tracking/attribution/compare')->assertForbidden();
    }
}
