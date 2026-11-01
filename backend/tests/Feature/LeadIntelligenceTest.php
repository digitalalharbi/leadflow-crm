<?php

namespace Tests\Feature;

use App\Domain\Leads\LeadIntelligence;
use App\Domain\Leads\NextBestAction;
use App\Domain\Leads\QueueRank;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Models\Lead;
use App\Models\LeadEngagement;
use App\Models\LeadMessage;
use App\Models\Project;
use App\Models\ProjectFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seven numbers a rep can read in a second — and the suggestions and queue
 * order that follow from them.
 */
class LeadIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Intel Co');
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'نورة العتيبي', 'phone' => '0551234567',
            'phone_normalized' => '+9665512'.self::uniqueDigits(5),
            'email' => 'noura@example.com', 'city' => 'الرياض', 'status' => 'contacted',
            'assigned_to' => $this->t['admin']->id, 'first_contact_at' => now()->subDays(2),
        ], $attributes)));
    }

    private function intel(Lead $lead): array
    {
        return $this->asTenant($this->t['tenant'], fn () => app(LeadIntelligence::class)->for($lead->fresh()));
    }

    private function engage(Lead $lead, string $type, int $times = 1): void
    {
        $this->asTenant($this->t['tenant'], function () use ($lead, $type, $times) {
            for ($i = 0; $i < $times; $i++) {
                LeadEngagement::create(['lead_id' => $lead->id, 'type' => $type, 'happened_at' => now()->subMinutes($i + 1)]);
            }
        });
    }

    private function reply(Lead $lead): void
    {
        $this->asTenant($this->t['tenant'], fn () => LeadMessage::create([
            'lead_id' => $lead->id, 'direction' => MessageDirection::Inbound, 'channel' => MessageChannel::Whatsapp,
            'provider' => 'whatsapp_cloud', 'message_type' => 'text', 'body' => 'مهتم', 'status' => 'received',
        ]));
    }

    // ---------------------------------------------------------------- the numbers

    public function test_every_number_is_between_0_and_100(): void
    {
        $lead = $this->lead();
        $this->engage($lead, LeadEngagement::FILE_OPENED, 9);
        $this->reply($lead);

        $intel = $this->intel($lead);

        foreach (['health', 'intent', 'engagement', 'response', 'staleness', 'contactability', 'completeness'] as $key) {
            $this->assertGreaterThanOrEqual(0, $intel[$key]['value'], $key);
            $this->assertLessThanOrEqual(100, $intel[$key]['value'], $key);
        }
    }

    public function test_an_engaged_lead_is_healthier_than_a_silent_one(): void
    {
        $engaged = $this->lead();
        $this->engage($engaged, LeadEngagement::FILE_OPENED, 2);
        $this->reply($engaged);
        $silent = $this->lead();

        $this->assertGreaterThan($this->intel($silent)['health']['value'], $this->intel($engaged)['health']['value']);
        $this->assertGreaterThan($this->intel($silent)['engagement']['value'], $this->intel($engaged)['engagement']['value']);
        $this->assertGreaterThan($this->intel($silent)['response']['value'], $this->intel($engaged)['response']['value']);
    }

    public function test_a_lead_nobody_has_touched_for_two_weeks_is_stale(): void
    {
        $lead = $this->lead(['last_contact_at' => now()->subDays(20)]);
        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['created_at' => now()->subDays(30)])->saveQuietly());

        $stale = $this->intel($lead);

        $this->assertSame(100, $stale['staleness']['value']);
        $this->assertGreaterThanOrEqual(20, $stale['staleness']['days']);
        $this->assertSame('low', $stale['staleness']['level'], 'a high staleness reads as a low level');
    }

    public function test_contactability_counts_the_channels_that_can_be_used(): void
    {
        $both = $this->intel($this->lead());
        $this->assertTrue($both['contactability']['channels']['whatsapp']);
        $this->assertTrue($both['contactability']['channels']['email']);
        $this->assertSame(100, $both['contactability']['value']);

        $blocked = $this->lead(['email' => null]);
        $this->asTenant($this->t['tenant'], fn () => $blocked->forceFill(['whatsapp_opt_out_at' => now()])->saveQuietly());
        $intel = $this->intel($blocked);
        $this->assertFalse($intel['contactability']['channels']['whatsapp']);
        $this->assertFalse($intel['contactability']['channels']['email']);
        $this->assertSame(45, $intel['contactability']['value'], 'only a phone call is left');
    }

    public function test_completeness_names_what_is_missing(): void
    {
        $intel = $this->intel($this->lead(['email' => null, 'city' => null]));

        $this->assertContains('email', $intel['completeness']['missing']);
        $this->assertContains('city', $intel['completeness']['missing']);
        $this->assertLessThan(100, $intel['completeness']['value']);
    }

    public function test_the_reason_is_a_few_words_about_what_the_customer_did(): void
    {
        $lead = $this->lead();
        $this->reply($lead);
        $this->engage($lead, LeadEngagement::FILE_OPENED, 2);

        $this->assertSame('ردّ على واتساب + فتح البروشور مرتين', $this->intel($lead)['reason']);
    }

    public function test_the_trend_is_fourteen_days_oldest_first(): void
    {
        $lead = $this->lead();
        $this->engage($lead, LeadEngagement::FILE_OPENED, 2);

        $trend = $this->intel($lead)['trend'];

        $this->assertCount(14, $trend);
        $this->assertSame(2, $trend[13], 'today is last');
    }

    public function test_a_closed_lead_has_no_health(): void
    {
        $this->assertSame(0, $this->intel($this->lead(['status' => 'lost']))['health']['value']);
    }

    // ----------------------------------------------------- the suggested action

    private function action(Lead $lead): array
    {
        return $this->asTenant($this->t['tenant'], function () use ($lead) {
            $lead = $lead->fresh();
            $lead->setRelation('intelligence', app(LeadIntelligence::class)->for($lead));

            return app(NextBestAction::class)->for($lead);
        });
    }

    public function test_an_engaged_silent_lead_is_suggested_a_whatsapp_with_the_reason(): void
    {
        $lead = $this->lead();
        $this->engage($lead, LeadEngagement::FILE_OPENED, 2);

        $action = $this->action($lead);

        $this->assertSame('send_whatsapp', $action['action']);
        $this->assertStringContainsString('فتح البروشور مرتين', $action['reason']);
    }

    public function test_an_unsent_brochure_is_suggested(): void
    {
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
        $this->asTenant($this->t['tenant'], fn () => ProjectFile::create([
            'project_id' => $project->id, 'name' => 'كتيب', 'kind' => 'brochure', 'path' => 'project-files/b.pdf',
            'mime' => 'application/pdf', 'size_bytes' => 10, 'is_active' => true,
        ]));

        $this->assertSame('send_brochure', $this->action($this->lead(['project_id' => $project->id]))['action']);
    }

    public function test_a_quiet_lead_is_nurtured_and_a_long_ignored_one_is_suggested_for_closing(): void
    {
        $quiet = $this->lead(['last_contact_at' => now()->subDays(9)]);
        $this->asTenant($this->t['tenant'], fn () => $quiet->forceFill(['created_at' => now()->subDays(12)])->saveQuietly());
        $this->assertSame('nurture', $this->action($quiet)['action']);

        $ignored = $this->lead(['last_contact_at' => now()->subDays(25)]);
        $this->asTenant($this->t['tenant'], function () use ($ignored) {
            $ignored->forceFill(['created_at' => now()->subDays(30)])->saveQuietly();
            for ($i = 0; $i < 3; $i++) {
                LeadMessage::create([
                    'lead_id' => $ignored->id, 'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Whatsapp,
                    'provider' => 'mock', 'message_type' => 'text', 'body' => 'x', 'status' => 'sent',
                ]);
            }
        });
        // The messages are recent as far as the 30-day window goes; age them past the staleness mark.
        LeadMessage::withoutGlobalScopes()->where('lead_id', $ignored->id)->update(['created_at' => now()->subDays(25)]);

        $this->assertSame('close_lost', $this->action($ignored)['action']);
    }

    // --------------------------------------------------------------- queue order

    public function test_inside_an_urgency_bucket_the_stronger_lead_comes_first_and_says_why(): void
    {
        $strong = $this->lead(['score' => 85, 'budget_max' => 1_500_000, 'first_contact_at' => null, 'status' => 'new']);
        $weak = $this->lead(['score' => 10, 'first_contact_at' => null, 'status' => 'new']);
        $this->reply($strong);
        $this->engage($strong, LeadEngagement::FILE_OPENED, 2);

        $rank = fn (Lead $l) => $this->asTenant($this->t['tenant'], function () use ($l) {
            $l = $l->fresh();
            $l->setRelation('intelligence', app(LeadIntelligence::class)->for($l));

            return app(QueueRank::class)->for($l, ['action' => 'call_now', 'urgency' => 'high']);
        });

        $this->assertGreaterThan($rank($weak)['within'], $rank($strong)['within']);
        $this->assertNotSame('', $rank($strong)['reason']);
        $this->assertLessThanOrEqual(3, count($rank($strong)['reasons']), 'three reasons at most');
    }

    public function test_urgency_is_never_outranked_by_a_good_looking_lead(): void
    {
        $late = $this->lead(['score' => 5, 'first_contact_at' => null, 'status' => 'new']);
        $this->asTenant($this->t['tenant'], fn () => $late->forceFill(['created_at' => now()->subHours(5)])->saveQuietly());
        $nice = $this->lead(['score' => 95, 'budget_max' => 2_000_000, 'next_follow_up_at' => now()->addHour()]);
        $this->reply($nice);

        $next = $this->actingAs($this->t['admin'])->getJson('/api/v1/work/next')->assertOk()->json('data');

        $this->assertSame($late->uuid, $next['lead']['id']);
        $this->assertArrayHasKey('rank', $next);
        $this->assertArrayHasKey('intelligence', $next);
    }

    // ------------------------------------------------------------------ the API

    public function test_the_lead_screen_carries_the_intelligence(): void
    {
        $lead = $this->lead();

        $data = $this->actingAs($this->t['admin'])->getJson("/api/v1/leads/{$lead->uuid}")->assertOk()->json('data');

        foreach (['health', 'intent', 'engagement', 'response', 'staleness', 'contactability', 'completeness'] as $key) {
            $this->assertArrayHasKey($key, $data['intelligence']);
        }
        $this->assertCount(14, $data['intelligence']['trend']);
    }

    public function test_the_list_carries_health_for_every_row(): void
    {
        $this->lead();
        $this->lead();

        $rows = $this->actingAs($this->t['admin'])->getJson('/api/v1/leads')->assertOk()->json('data');

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertArrayHasKey('value', $row['health']);
        }
    }
}
