<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\Role;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadMessage;
use App\Models\LeadNote;
use App\Models\LeadSourceEvent;
use App\Models\ScheduledAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two records that are one person: found by evidence, merged by a manager, and
 * nothing — no message, no campaign, no sale — lost on the way.
 */
class SmartMergeTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Merge Co');
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عبدالله الدوسري', 'phone' => '0551234567',
            'phone_normalized' => '+9665512'.random_int(10000, 99999), 'status' => 'new',
        ], $attributes)));
    }

    private function duplicates(Lead $lead): array
    {
        return $this->actingAs($this->t['admin'])->getJson("/api/v1/leads/{$lead->uuid}/duplicates")->assertOk()->json('data.duplicates');
    }

    // ----------------------------------------------------------------- finding

    public function test_the_same_email_is_a_suggestion_with_its_reason(): void
    {
        $a = $this->lead(['email' => 'abdullah@example.com']);
        $b = $this->lead(['email' => 'Abdullah@Example.com', 'full_name' => 'عبد الله']);

        $found = $this->duplicates($a);

        $this->assertCount(1, $found);
        $this->assertSame($b->uuid, $found[0]['id']);
        $this->assertSame(['email'], $found[0]['reasons']);
    }

    public function test_the_same_phone_written_two_ways_is_found(): void
    {
        $a = $this->lead(['phone' => '0551234567', 'phone_normalized' => '+966551234567']);
        $b = $this->lead(['phone' => '966551234567', 'phone_normalized' => '+9660551234567']);

        $this->assertSame([$b->uuid], collect($this->duplicates($a))->pluck('id')->all());
        $this->assertSame(['phone'], $this->duplicates($a)[0]['reasons']);
    }

    public function test_the_same_providers_submission_id_is_found(): void
    {
        $a = $this->lead();
        $b = $this->lead(['phone' => '0500000009', 'phone_normalized' => '+966500000009']);
        $this->asTenant($this->t['tenant'], function () use ($a, $b) {
            LeadSourceEvent::create(['lead_id' => $a->id, 'provider' => 'meta', 'provider_lead_id' => 'l:777', 'received_at' => now()]);
            LeadSourceEvent::create(['lead_id' => $b->id, 'provider' => 'meta', 'provider_lead_id' => 'l:777', 'received_at' => now()]);
        });

        $this->assertSame(['provider_lead_id'], $this->duplicates($a)[0]['reasons']);
    }

    public function test_two_people_with_the_same_name_are_not_suggested(): void
    {
        $a = $this->lead(['full_name' => 'محمد العتيبي']);
        $this->lead(['full_name' => 'محمد العتيبي', 'phone' => '0500000010', 'phone_normalized' => '+966500000010']);

        $this->assertSame([], $this->duplicates($a));
    }

    public function test_a_rep_cannot_look_for_or_merge_duplicates(): void
    {
        $a = $this->lead();
        $b = $this->lead(['phone_normalized' => '+966500000011']);
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->actingAs($rep)->getJson("/api/v1/leads/{$a->uuid}/duplicates")->assertForbidden();
        $this->actingAs($rep)->postJson("/api/v1/leads/{$a->uuid}/merge", ['duplicate_id' => $b->uuid])->assertForbidden();
    }

    // ----------------------------------------------------------------- merging

    public function test_everything_the_duplicate_had_becomes_the_kept_leads(): void
    {
        $keep = $this->lead(['email' => null, 'city' => null]);
        $dup = $this->lead(['email' => 'abdullah@example.com', 'city' => 'جدة', 'phone_normalized' => '+966500000012']);

        $this->asTenant($this->t['tenant'], function () use ($dup) {
            LeadMessage::create(['lead_id' => $dup->id, 'direction' => MessageDirection::Inbound, 'channel' => MessageChannel::Whatsapp, 'provider' => 'whatsapp_cloud', 'message_type' => 'text', 'body' => 'مهتم', 'status' => 'received']);
            LeadNote::create(['lead_id' => $dup->id, 'user_id' => $this->t['admin']->id, 'body' => 'طلب جدول الدفعات']);
            Call::create(['lead_id' => $dup->id, 'user_id' => $this->t['admin']->id, 'provider' => 'manual', 'direction' => 'outbound', 'status' => 'completed', 'started_at' => now()]);
            FollowUp::create(['lead_id' => $dup->id, 'assigned_to' => $this->t['admin']->id, 'due_at' => now()->addDay(), 'status' => 'pending']);
            ScheduledAction::create(['lead_id' => $dup->id, 'rule_name' => 'قاعدة', 'action' => ['type' => 'send_whatsapp'], 'run_at' => now()->addDay(), 'status' => ScheduledAction::PENDING]);
        });

        $response = $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$keep->uuid}/merge", ['duplicate_id' => $dup->uuid])->assertOk();

        $merged = $this->asTenant($this->t['tenant'], fn () => Lead::find($keep->id));
        $this->assertSame('abdullah@example.com', $merged->email, 'blanks are filled');
        $this->assertSame('جدة', $merged->city);

        foreach ([LeadMessage::class, LeadNote::class, Call::class, FollowUp::class] as $model) {
            $this->assertSame(1, $this->asTenant($this->t['tenant'], fn () => $model::where('lead_id', $keep->id)->count()), class_basename($model).' moved');
            $this->assertSame(0, $this->asTenant($this->t['tenant'], fn () => $model::where('lead_id', $dup->id)->count()));
        }

        $this->assertSame(ScheduledAction::CANCELLED, ScheduledAction::withoutGlobalScopes()->where('lead_id', $keep->id)->sole()->status, 'a waiting automated step is cancelled, not run');
        $this->assertNotEmpty($response->json('data.moved'));
    }

    public function test_the_duplicate_is_retired_and_remembers_where_it_went(): void
    {
        $keep = $this->lead();
        $dup = $this->lead(['phone_normalized' => '+966500000013']);

        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$keep->uuid}/merge", ['duplicate_id' => $dup->uuid])->assertOk();

        $this->assertNull($this->asTenant($this->t['tenant'], fn () => Lead::find($dup->id)), 'gone from every list');
        $retired = Lead::withoutGlobalScopes()->withTrashed()->find($dup->id);
        $this->assertSame($keep->id, $retired->merged_into_id);
        $this->assertNotNull($retired->merged_at);
    }

    public function test_the_merge_is_in_the_history_with_what_was_merged(): void
    {
        $keep = $this->lead();
        $dup = $this->lead(['full_name' => 'عبد الله', 'phone_normalized' => '+966500000014', 'platform' => 'tiktok', 'campaign_name' => 'حملة الصيف']);

        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$keep->uuid}/merge", ['duplicate_id' => $dup->uuid])->assertOk();

        $entry = collect($this->actingAs($this->t['admin'])->getJson("/api/v1/leads/{$keep->uuid}/journey")->json('data.entries'))->firstWhere('type', 'merged');
        $this->assertNotNull($entry);
        $this->assertSame('عبد الله', $entry['meta']['merged']['name'] ?? $this->asTenant($this->t['tenant'], fn () => LeadActivity::where('lead_id', $keep->id)->where('type', ActivityType::Merged)->first()->meta['merged']['name']));
    }

    public function test_the_duplicates_campaign_is_not_forgotten(): void
    {
        $keep = $this->lead(['platform' => 'facebook', 'campaign_name' => 'النرجس']);
        $dup = $this->lead(['phone_normalized' => '+966500000015', 'platform' => 'tiktok', 'campaign_name' => 'حملة تيك توك']);

        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$keep->uuid}/merge", ['duplicate_id' => $dup->uuid])->assertOk();

        $merged = $this->asTenant($this->t['tenant'], fn () => Lead::find($keep->id));
        $this->assertSame('النرجس', $merged->campaign_name, "the kept lead's own attribution is untouched");

        $event = LeadSourceEvent::withoutGlobalScopes()->where('lead_id', $keep->id)->where('campaign_name', 'حملة تيك توك')->first();
        $this->assertNotNull($event, "the duplicate's campaign is kept as a source");
        $this->assertTrue($event->was_duplicate);
    }

    public function test_a_sale_on_the_duplicate_is_the_kept_leads_sale(): void
    {
        $keep = $this->lead(['status' => 'contacted']);
        $dup = $this->lead(['phone_normalized' => '+966500000016', 'status' => 'won', 'deal_value_minor' => 90_000_000]);

        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$keep->uuid}/merge", ['duplicate_id' => $dup->uuid])->assertOk();

        $merged = $this->asTenant($this->t['tenant'], fn () => Lead::find($keep->id));
        $this->assertSame('won', $merged->status->value);
        $this->assertSame(90_000_000, $merged->deal_value_minor);
        $this->assertNotNull($merged->won_at);
    }

    public function test_a_lead_cannot_be_merged_into_itself_or_across_workspaces(): void
    {
        $a = $this->lead();
        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$a->uuid}/merge", ['duplicate_id' => $a->uuid])->assertStatus(500)->assertJsonPath('success', false);

        $other = $this->provisionTenant('Other Co');
        $foreign = $this->asTenant($other['tenant'], fn () => Lead::create(['full_name' => 'س', 'phone' => '0551111111', 'phone_normalized' => '+966551111111', 'status' => 'new']));
        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$a->uuid}/merge", ['duplicate_id' => $foreign->uuid])->assertNotFound();
    }
}
