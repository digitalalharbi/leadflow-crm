<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\Campaign;
use App\Models\Creative;
use App\Models\CreativeDraft;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use App\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CreativeOperationsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Ad $ad;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => null]);
        $this->t = $this->provisionTenant('Creative Ops');

        $this->ad = $this->asTenant($this->t['tenant'], function () {
            $campaign = Campaign::create(['platform' => 'meta', 'external_id' => 'c1', 'name' => 'حملة', 'origin' => 'sync']);
            $creative = Creative::create(['platform' => 'meta', 'external_id' => 'cr1', 'format' => 'video', 'headline' => 'شقق جاهزة', 'thumbnail_url' => 'https://example.test/t.jpg']);
            $ad = Ad::create(['campaign_id' => $campaign->id, 'creative_id' => $creative->id, 'external_id' => 'a1', 'name' => 'فيديو ١', 'status' => 'active']);

            MetricSnapshot::create(['campaign_id' => $campaign->id, 'ad_id' => $ad->id, 'platform' => 'meta', 'level' => 'ad', 'date' => now()->toDateString(), 'spend_minor' => 30000, 'impressions' => 2000, 'clicks' => 40]);

            foreach (['qualified', 'won', 'new'] as $i => $status) {
                $lead = Lead::create(['full_name' => 'ع', 'phone_normalized' => '+96650044400'.$i, 'status' => $status]);
                DB::table('leads')->where('id', $lead->id)->update(['ad_ref_id' => $ad->id, 'campaign_ref_id' => $campaign->id, 'deal_value_minor' => $status === 'won' ? 5000000 : null]);
            }

            return $ad;
        });
    }

    public function test_each_ad_is_shown_with_its_preview_click_through_and_what_it_brought(): void
    {
        $row = $this->actingAs($this->t['admin'])->getJson('/api/v1/creatives')->assertOk()->json('data.creatives.0');

        $this->assertSame('https://example.test/t.jpg', $row['creative']['thumbnail_url']);
        $this->assertEquals(2.0, $row['ctr']);
        $this->assertSame(3, $row['leads']);
        $this->assertSame(2, $row['qualified']);
        $this->assertSame(1, $row['won']);
        $this->assertEquals(50000.0, $row['revenue']);
        $this->assertEquals(150.0, $row['cpql']);
        $this->assertTrue($row['on_platform']);
    }

    public function test_asking_for_ai_drafts_without_ai_is_refused_plainly_and_a_person_can_write_them(): void
    {
        $this->actingAs($this->t['admin'])->postJson("/api/v1/creatives/{$this->ad->uuid}/generate", ['kind' => 'hook'])
            ->assertStatus(409)->assertJsonPath('message', 'ai_blocked');

        $draft = $this->actingAs($this->t['admin'])->postJson("/api/v1/creatives/{$this->ad->uuid}/drafts", ['kind' => 'hook', 'items' => ['جاهزة للسكن اليوم']])
            ->assertCreated()->json('data.draft');

        $this->assertSame('user', $draft['source']);
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/creative-drafts/{$draft['id']}", ['status' => 'dismissed'])->assertJsonPath('data.draft.status', 'dismissed');
    }

    public function test_with_ai_the_creative_agent_writes_the_drafts_through_its_tool(): void
    {
        $this->asTenant($this->t['tenant'], fn () => TenantSetting::query()->first()->forceFill(['ai_api_key' => 'sk-ant-test-0000000000000000'])->save());
        $turns = [
            ['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'draft_creative', 'input' => ['ad_id' => $this->ad->uuid, 'kind' => 'copy', 'items' => ['نص أ', 'نص ب', 'نص ج']]]], 'stop_reason' => 'tool_use', 'usage' => []],
            ['content' => [['type' => 'text', 'text' => 'حفظت ثلاث مسودات.']], 'stop_reason' => 'end_turn', 'usage' => []],
        ];
        Http::fake(['api.anthropic.com/*' => function () use (&$turns) {
            return Http::response(array_shift($turns));
        }]);

        $data = $this->actingAs($this->t['admin'])->postJson("/api/v1/creatives/{$this->ad->uuid}/generate", ['kind' => 'copy'])->assertOk()->json('data');

        $this->assertCount(1, $data['drafts']);
        $this->assertSame(['نص أ', 'نص ب', 'نص ج'], $data['drafts'][0]['items']);
        $this->assertSame('agent', $this->asTenant($this->t['tenant'], fn () => CreativeDraft::first()->source));
    }

    public function test_reps_do_not_see_creative_money(): void
    {
        $rep = $this->makeUser($this->t['tenant']);
        $this->actingAs($rep)->getJson('/api/v1/creatives')->assertForbidden();
    }
}
