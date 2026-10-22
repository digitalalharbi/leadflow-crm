<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Models\Appointment;
use App\Models\AutomationActionLog;
use App\Models\Call;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadEngagement;
use App\Models\LeadMessage;
use App\Models\LeadSourceEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** One list of everything that has happened to a lead, newest first, with who did it. */
class LeadJourneyFeedTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Journey Co');
    }

    private function feed(Lead $lead): array
    {
        return $this->actingAs($this->t['admin'])->getJson("/api/v1/leads/{$lead->uuid}/journey")->assertOk()->json('data');
    }

    public function test_every_kind_of_event_lands_in_one_list_newest_first(): void
    {
        $lead = $this->asTenant($this->t['tenant'], function () {
            $lead = Lead::create(['full_name' => 'فهد', 'phone' => '0551234567', 'phone_normalized' => '+966551234567', 'status' => 'new', 'platform' => 'facebook', 'campaign_name' => 'النرجس']);

            AutomationActionLog::create(['lead_id' => $lead->id, 'rule_name' => 'رحلة العميل', 'action_type' => 'send_whatsapp', 'result' => 'completed', 'description' => 'أُرسلت رسالة واتساب', 'happened_at' => now()->subMinutes(50)]);
            LeadMessage::create(['lead_id' => $lead->id, 'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Whatsapp, 'provider' => 'mock', 'message_type' => 'text', 'body' => 'مرحبًا', 'status' => 'sent']);
            LeadMessage::create(['lead_id' => $lead->id, 'direction' => MessageDirection::Inbound, 'channel' => MessageChannel::Whatsapp, 'provider' => 'whatsapp_cloud', 'message_type' => 'text', 'body' => 'مهتم', 'status' => 'received']);
            LeadMessage::create(['lead_id' => $lead->id, 'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Email, 'provider' => 'smtp', 'message_type' => 'email', 'subject' => 'أهلًا', 'body' => 'x', 'status' => 'sent']);
            LeadEngagement::create(['lead_id' => $lead->id, 'type' => LeadEngagement::FILE_OPENED, 'happened_at' => now()->subMinutes(30)]);
            Call::create(['lead_id' => $lead->id, 'user_id' => $this->t['admin']->id, 'provider' => 'manual', 'direction' => 'outbound', 'status' => 'completed', 'duration_seconds' => 95, 'started_at' => now()->subMinutes(20)]);
            Appointment::create(['lead_id' => $lead->id, 'assigned_to' => $this->t['admin']->id, 'created_by' => $this->t['admin']->id, 'scheduled_at' => now()->addDay(), 'status' => 'scheduled']);
            LeadActivity::create(['lead_id' => $lead->id, 'type' => ActivityType::StatusChanged, 'meta' => ['from' => 'new', 'to' => 'contacted']]);
            LeadActivity::create(['lead_id' => $lead->id, 'type' => ActivityType::ScoreChanged, 'meta' => ['from' => 10, 'to' => 40]]);
            $lead->forceFill(['deal_value_minor' => 125_000_000])->saveQuietly();
            LeadActivity::create(['lead_id' => $lead->id, 'type' => ActivityType::Won, 'user_id' => $this->t['admin']->id]);

            return $lead;
        });

        $data = $this->feed($lead);
        $kinds = collect($data['entries'])->pluck('kind')->unique()->all();

        foreach (['source', 'automation', 'whatsapp', 'email', 'file', 'call', 'appointment', 'stage', 'score', 'revenue'] as $kind) {
            $this->assertContains($kind, $kinds, "{$kind} is in the journey");
        }

        $times = collect($data['entries'])->pluck('at')->all();
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times, 'newest first');
        $this->assertSame(1, $data['counts']['revenue']);
    }

    public function test_it_says_who_did_each_thing(): void
    {
        $lead = $this->asTenant($this->t['tenant'], function () {
            $lead = Lead::create(['full_name' => 'فهد', 'phone' => '0551234567', 'phone_normalized' => '+966551234567', 'status' => 'new']);
            LeadMessage::create(['lead_id' => $lead->id, 'direction' => MessageDirection::Inbound, 'channel' => MessageChannel::Whatsapp, 'provider' => 'whatsapp_cloud', 'message_type' => 'text', 'body' => 'مهتم', 'status' => 'received']);
            LeadMessage::create(['lead_id' => $lead->id, 'user_id' => $this->t['admin']->id, 'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Whatsapp, 'provider' => 'mock', 'message_type' => 'text', 'body' => 'أهلًا', 'status' => 'sent']);
            LeadMessage::create(['lead_id' => $lead->id, 'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Whatsapp, 'provider' => 'mock', 'message_type' => 'text', 'body' => 'آلي', 'status' => 'sent']);

            return $lead;
        });

        $actors = collect($this->feed($lead)['entries'])->where('kind', 'whatsapp')->pluck('actor')->sort()->values()->all();

        $this->assertSame(['automation', 'customer', 'person'], $actors);
    }

    public function test_a_repeat_arrival_is_its_own_entry(): void
    {
        $lead = $this->asTenant($this->t['tenant'], fn () => Lead::create(['full_name' => 'فهد', 'phone' => '0551234567', 'phone_normalized' => '+966551234567', 'status' => 'new']));
        $this->asTenant($this->t['tenant'], function () use ($lead) {
            LeadSourceEvent::create(['lead_id' => $lead->id, 'provider' => 'meta', 'platform' => 'facebook', 'was_duplicate' => false, 'received_at' => now()->subDay()]);
            LeadSourceEvent::create(['lead_id' => $lead->id, 'provider' => 'google_sheets', 'platform' => 'google', 'was_duplicate' => true, 'received_at' => now()]);
        });

        $titles = collect($this->feed($lead)['entries'])->where('kind', 'source')->pluck('title')->all();

        $this->assertSame(['وصل مرة أخرى', 'وصل العميل'], $titles);
    }

    public function test_another_workspaces_lead_is_not_readable(): void
    {
        $other = $this->provisionTenant('Other Co');
        $lead = $this->asTenant($other['tenant'], fn () => Lead::create(['full_name' => 'س', 'phone' => '0551111111', 'phone_normalized' => '+966551111111', 'status' => 'new']));

        $this->actingAs($this->t['admin'])->getJson("/api/v1/leads/{$lead->uuid}/journey")->assertNotFound();
    }
}
