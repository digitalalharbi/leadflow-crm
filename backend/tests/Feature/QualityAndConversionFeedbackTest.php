<?php

namespace Tests\Feature;

use App\Domain\Conversions\ConversionEvent;
use App\Domain\Conversions\ExporterRegistry;
use App\Models\Ad;
use App\Models\AdConnection;
use App\Models\AdSet;
use App\Models\Campaign;
use App\Models\ConversionExport;
use App\Models\Creative;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QualityAndConversionFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Quality Feedback');
    }

    private function seedChain(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            $n = 0;
            foreach (['meta' => ['qualified', 'won', 'new'], 'tiktok' => ['new', 'invalid']] as $platform => $statuses) {
                $c = Campaign::create(['platform' => $platform, 'external_id' => "c-{$platform}", 'name' => "حملة {$platform}", 'origin' => 'sync']);
                $s = AdSet::create(['campaign_id' => $c->id, 'external_id' => "s-{$platform}", 'name' => "مجموعة {$platform}"]);
                $cr = Creative::create(['platform' => $platform, 'external_id' => "cr-{$platform}", 'headline' => "عنوان {$platform}"]);
                $ad = Ad::create(['campaign_id' => $c->id, 'ad_set_id' => $s->id, 'creative_id' => $cr->id, 'external_id' => "a-{$platform}", 'name' => "إعلان {$platform}"]);
                MetricSnapshot::create(['campaign_id' => $c->id, 'ad_set_id' => $s->id, 'ad_id' => $ad->id, 'platform' => $platform, 'level' => 'ad', 'date' => now()->toDateString(), 'spend_minor' => 60000]);

                foreach ($statuses as $status) {
                    $lead = Lead::create(['full_name' => 'ع', 'phone_normalized' => '+96650077'.str_pad((string) (++$n), 4, '0', STR_PAD_LEFT), 'status' => $status]);
                    DB::table('leads')->where('id', $lead->id)->update([
                        'campaign_ref_id' => $c->id, 'ad_set_ref_id' => $s->id, 'ad_ref_id' => $ad->id,
                        'deal_value_minor' => $status === 'won' ? 30000000 : null,
                    ]);
                }
            }
        });
    }

    public function test_every_level_tells_the_same_story_from_spend_to_revenue(): void
    {
        $this->seedChain();

        $totals = [];
        foreach (['platform', 'campaign', 'ad_set', 'ad', 'creative'] as $level) {
            $rows = $this->actingAs($this->t['admin'])->getJson("/api/v1/reports/quality?level={$level}")->assertOk()->json('data.rows');
            $this->assertCount(2, $rows, $level);
            $totals[$level] = [array_sum(array_column($rows, 'qualified')), array_sum(array_column($rows, 'leads')), array_sum(array_column($rows, 'spend'))];
        }

        // The same leads and money at every level.
        $this->assertCount(1, array_unique(array_map('json_encode', $totals)));

        $meta = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/reports/quality?level=creative')->json('data.rows'))->firstWhere('platform', 'meta');
        $this->assertSame('عنوان meta', $meta['label']);
        $this->assertSame(3, $meta['leads']);
        $this->assertSame(3, $meta['valid']);
        $this->assertSame(2, $meta['qualified']);
        $this->assertEquals(600.0, $meta['cac']);           // 600 spent, one sale
        $this->assertEquals(500.0, $meta['roas']);          // 300,000 / 600

        $tiktok = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/reports/quality?level=platform')->json('data.rows'))->firstWhere('key', 'tiktok');
        $this->assertSame(1, $tiktok['valid']);
        $this->assertNull($tiktok['cpql'], 'A cost per qualified was invented for a campaign with none.');
        $this->assertNull($tiktok['cac']);
    }

    private function export(string $platform, ConversionEvent $event = ConversionEvent::Won): ConversionExport
    {
        return $this->asTenant($this->t['tenant'], function () use ($platform, $event) {
            $lead = Lead::create(['full_name' => 'ع', 'phone_normalized' => '+966500888'.random_int(100, 999), 'email' => 'a@example.test', 'status' => 'won', 'deal_value_minor' => 100000, 'platform' => $platform]);

            return ConversionExport::create(['lead_id' => $lead->id, 'platform' => $platform, 'event' => $event->value, 'occurred_at' => now(), 'value' => 1000, 'currency' => 'SAR', 'status' => 'pending']);
        });
    }

    private function send(string $platform, array $credentials, ConversionEvent $event = ConversionEvent::Won): array
    {
        $export = $this->export($platform, $event);

        return $this->asTenant($this->t['tenant'], fn () => app(ExporterRegistry::class)->get($platform)->send($export->fresh(), $credentials));
    }

    public function test_tiktok_authenticates_with_its_header_and_reads_refusals_from_the_body(): void
    {
        Http::fake(['*' => Http::sequence()->push(['code' => 0])->push(['code' => 40001, 'message' => 'bad event'])]);

        $this->assertTrue($this->send('tiktok', ['access_token' => 'tt', 'event_set_id' => 'es'])['ok']);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Access-Token', 'tt') && $r['data'][0]['event_id'] !== null);

        $this->assertFalse($this->send('tiktok', ['access_token' => 'tt', 'event_set_id' => 'es'])['ok']);
    }

    public function test_snapchat_sends_a_data_array_with_the_token_on_the_url(): void
    {
        Http::fake(['*' => Http::response(['status' => 'VALID'])]);

        $this->send('snapchat', ['access_token' => 'sn', 'pixel_id' => 'px']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v3/px/events?access_token=sn') && isset($r['data'][0]['event_id']));
    }

    public function test_google_mints_an_access_token_and_dedupes_by_order_id(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29']),
            '*uploadClickConversions' => Http::response(['results' => [[]]]),
        ]);

        $ok = $this->send('google_ads', ['customer_id' => '111', 'developer_token' => 'dev', 'client_id' => 'c', 'client_secret' => 's', 'refresh_token' => 'r', 'conversion_action_id' => '9']);

        $this->assertTrue($ok['ok']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'uploadClickConversions')
            && $r->hasHeader('Authorization', 'Bearer ya29') && $r->hasHeader('developer-token', 'dev')
            && str_starts_with($r['conversions'][0]['orderId'], 'leadflow-'));
    }

    public function test_linkedin_and_x_need_their_event_mapping_and_sign_correctly(): void
    {
        Http::fake(['*' => Http::response([], 201)]);

        $missing = $this->send('linkedin', ['access_token' => 'li'], ConversionEvent::Appointment);
        $this->assertFalse($missing['ok']);
        $this->assertStringContainsString('No LinkedIn conversion rule', $missing['error']);

        $this->assertTrue($this->send('linkedin', ['access_token' => 'li', 'conversion_won' => '777'])['ok']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rest/conversionEvents') && $r['conversion'] === 'urn:lla:llaPartnerConversion:777' && $r->hasHeader('LinkedIn-Version'));

        $this->assertTrue($this->send('x', ['consumer_key' => 'a', 'consumer_secret' => 'b', 'access_token' => 'c', 'access_token_secret' => 'd', 'pixel_id' => 'px', 'event_won' => 'tw-1'])['ok']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/measurement/conversions/px')
            && str_starts_with($r->header('Authorization')[0] ?? '', 'OAuth ')
            && $r['conversions'][0]['event_id'] === 'tw-1' && str_starts_with($r['conversions'][0]['conversion_id'], 'leadflow-'));
    }

    public function test_health_says_what_is_missing_and_moves_only_on_an_accepted_conversion(): void
    {
        $this->asTenant($this->t['tenant'], fn () => AdConnection::create(['platform' => 'tiktok', 'credentials' => ['access_token' => 'tt']]));

        $health = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/conversions/health')->assertOk()->json('data.platforms'))->keyBy('platform');

        $this->assertCount(6, $health);
        $this->assertSame('not_configured', $health['tiktok']['state']);
        $this->assertSame(['event_set_id'], $health['tiktok']['missing']);

        $export = $this->export('meta');
        $this->asTenant($this->t['tenant'], fn () => $export->update(['status' => 'sent', 'sent_at' => now()]));
        $this->asTenant($this->t['tenant'], fn () => Integration::create(['provider' => 'meta', 'name' => 'Meta', 'status' => 'connected', 'credentials' => ['pixel_id' => 'p', 'access_token' => 't'], 'is_active' => true]));

        $meta = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/conversions/health')->json('data.platforms'))->firstWhere('platform', 'meta');
        $this->assertSame('ready', $meta['state'], 'a sent conversion counted as live outside production');

        $this->asProductionDeployment();
        $meta = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/conversions/health')->json('data.platforms'))->firstWhere('platform', 'meta');
        $this->assertSame('production_verified', $meta['state']);
        $this->assertSame(1, $meta['counts']['sent']);
    }
}
