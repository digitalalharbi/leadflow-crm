<?php

namespace Tests\Feature;

use App\Domain\Automation\ActionExecutor;
use App\Domain\Automation\Recovery;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Jobs\RunScheduledAction;
use App\Models\AutomationActionLog;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadAnswer;
use App\Models\LeadMessage;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\ProjectQuestion;
use App\Models\ScheduledAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A channel that fails is not the end: the other channel, then a person.
 */
class AutomationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([RunScheduledAction::class]);
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $this->t = $this->provisionTenant('Recover Co');
    }

    private function connectWhatsapp(): void
    {
        $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'whatsapp', 'name' => 'WhatsApp Business', 'status' => Integration::CONNECTED,
            'config' => ['phone_number_id' => '1555000999'], 'credentials' => ['verify_token' => 'v', 'app_secret' => 's'], 'is_active' => true,
        ]));
    }

    private function lead(array $attributes = []): Lead
    {
        $guarded = array_intersect_key($attributes, array_flip(['email_bounced_at', 'whatsapp_opt_out_at', 'email_opt_out_at', 'automation_state']));

        return $this->asTenant($this->t['tenant'], function () use ($attributes, $guarded) {
            $lead = Lead::create(array_merge([
                'full_name' => 'سعد', 'phone' => '0551234567', 'phone_normalized' => '+9665512'.self::uniqueDigits(5),
                'email' => 'saad'.random_int(1, 99999).'@example.com', 'status' => 'contacted', 'assigned_to' => $this->t['admin']->id,
            ], array_diff_key($attributes, $guarded)));
            if ($guarded) {
                $lead->forceFill($guarded)->saveQuietly();
            }

            return $lead;
        });
    }

    private function templates(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            MessageTemplate::create(['name' => 'ترحيب واتساب', 'body' => 'مرحبًا {{name}}', 'channel' => 'whatsapp', 'purpose' => 'reminder', 'is_active' => true]);
            MessageTemplate::create(['name' => 'تذكير بريد', 'subject' => 'تذكير', 'body' => 'مرحبًا {{name}}', 'channel' => 'email', 'purpose' => 'reminder', 'is_active' => true]);
        });
    }

    private function act(Lead $lead, array $action): array
    {
        return $this->asTenant($this->t['tenant'], fn () => app(ActionExecutor::class)->execute($lead->fresh(), $action));
    }

    private function messages(Lead $lead)
    {
        return $this->asTenant($this->t['tenant'], fn () => LeadMessage::where('lead_id', $lead->id)->where('direction', 'outbound')->get());
    }

    // ----------------------------------------------------------- what is a fault

    public function test_only_channel_faults_are_recovered(): void
    {
        foreach ([
            'WhatsApp is not connected for this workspace.' => 'provider_unavailable',
            'Email to this address bounced. Correct the address before writing again.' => 'bounced',
            "This conversation is outside WhatsApp's 24-hour window, and this message has no approved template to start it with." => 'channel_closed',
            'This lead has no email address.' => 'no_contact_point',
            'Delivery failed.' => 'provider_unavailable',
        ] as $why => $cause) {
            $this->assertSame($cause, Recovery::cause($why), $why);
        }

        foreach (['This lead has asked not to receive WhatsApp messages.', 'This lead asked not to receive email.', 'Too many messages to this lead.', 'The message is empty.', "This template needs the lead's city, and this lead does not have one."] as $why) {
            $this->assertNull(Recovery::cause($why), "{$why} is not ours to route around");
        }
    }

    // ----------------------------------------------------------- the other channel

    public function test_a_whatsapp_that_cannot_be_sent_goes_by_email_and_is_logged_as_recovered(): void
    {
        $this->templates();   // WhatsApp is not connected: the send fails
        $lead = $this->lead();

        $result = $this->act($lead, ['type' => 'send_whatsapp', 'purpose' => 'reminder']);

        $this->assertTrue($result['ok']);
        $this->assertSame('recovered', $result['result']);
        $this->assertStringContainsString('البريد', $result['description']);
        $sent = $this->messages($lead);
        $this->assertSame(['email'], $sent->pluck('channel.value')->unique()->values()->all(), 'the customer got it, by the other channel');
    }

    public function test_a_bounced_email_goes_by_whatsapp(): void
    {
        $this->connectWhatsapp();
        $this->templates();
        $lead = $this->lead(['email_bounced_at' => now()]);

        $result = $this->act($lead, ['type' => 'send_email', 'purpose' => 'reminder']);

        $this->assertSame('recovered', $result['result']);
        $this->assertSame(['whatsapp'], $this->messages($lead)->pluck('channel.value')->unique()->values()->all());
    }

    public function test_a_customer_who_opted_out_of_a_channel_is_not_chased_on_the_other_one(): void
    {
        $this->connectWhatsapp();
        $this->templates();
        $lead = $this->lead(['whatsapp_opt_out_at' => now()]);

        $result = $this->act($lead, ['type' => 'send_whatsapp', 'purpose' => 'reminder']);

        $this->assertSame('skipped', $result['result'] ?? null, 'a refusal, not a failure');
        $this->assertCount(0, $this->messages($lead), 'nothing went by email');
        $this->assertNull($this->asTenant($this->t['tenant'], fn () => $lead->fresh()->needs_human_reason), 'and nobody was called in: it is the customer\'s decision, not a fault');
    }

    public function test_the_brochure_is_recovered_on_the_other_channel_too(): void
    {
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
        $this->asTenant($this->t['tenant'], fn () => ProjectFile::create([
            'project_id' => $project->id, 'name' => 'كتيب', 'kind' => 'brochure', 'path' => 'project-files/b.pdf', 'mime' => 'application/pdf', 'size_bytes' => 10, 'is_active' => true,
        ]));
        Storage::fake('local');
        Storage::disk('local')->put('project-files/b.pdf', 'x');
        $lead = $this->lead(['project_id' => $project->id]);

        $result = $this->act($lead, ['type' => 'share_file', 'kind' => 'brochure', 'send' => true, 'channel' => 'whatsapp']);

        $this->assertSame('recovered', $result['result'], $result['description']);
        $this->assertSame(['email'], $this->messages($lead)->pluck('channel.value')->unique()->values()->all());
    }

    // ----------------------------------------------------------- then a person

    public function test_when_both_channels_fail_the_lead_is_handed_to_a_person_with_the_reason(): void
    {
        $this->templates();   // WhatsApp not connected
        $lead = $this->lead(['email' => null]);   // and no email

        $result = $this->act($lead, ['type' => 'send_whatsapp', 'purpose' => 'reminder']);

        $this->assertSame('needs_human', $result['result']);
        $fresh = $this->asTenant($this->t['tenant'], fn () => $lead->fresh());
        $this->assertSame(Lead::AUTOMATION_HANDED_OFF, $fresh->automation_state);
        $this->assertSame('delivery_failed', $fresh->needs_human_reason);

        $alert = DatabaseNotification::where('notifiable_id', $this->t['admin']->id)->get()->firstWhere('data.kind', 'delivery_failed');
        $this->assertNotNull($alert, 'the owner is told');
        $this->assertSame('critical', $alert->data['severity']);

        $next = $this->actingAs($this->t['admin'])->getJson('/api/v1/work/next')->assertOk()->json('data');
        $this->assertSame($lead->uuid, $next['lead']['id']);
        $this->assertSame('call_now', $next['action']['action']);
    }

    public function test_a_recovery_is_never_itself_recovered_so_it_cannot_loop(): void
    {
        $this->templates();
        $lead = $this->lead();
        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['email_bounced_at' => now()])->saveQuietly());

        // WhatsApp is down and the email bounced: one attempt each, then a person — not an endless exchange.
        $result = $this->act($lead, ['type' => 'send_whatsapp', 'purpose' => 'reminder']);

        $this->assertSame('needs_human', $result['result']);
        $this->assertCount(0, $this->messages($lead));
    }

    // ----------------------------------------------------- failures after sending

    private function failedMessage(Lead $lead, string $channel, array $attributes = []): LeadMessage
    {
        return $this->asTenant($this->t['tenant'], fn () => LeadMessage::create(array_merge([
            'lead_id' => $lead->id, 'direction' => MessageDirection::Outbound, 'channel' => $channel === 'email' ? MessageChannel::Email : MessageChannel::Whatsapp,
            'provider' => 'mock', 'message_type' => 'text', 'body' => 'x', 'status' => 'sent', 'template_name' => $channel === 'email' ? 'تذكير بريد' : 'ترحيب واتساب',
        ], $attributes)));
    }

    public function test_a_whatsapp_that_fails_after_it_was_sent_is_resent_by_email_once(): void
    {
        $this->templates();
        $lead = $this->lead();
        $message = $this->failedMessage($lead, 'whatsapp');

        $this->asTenant($this->t['tenant'], function () use ($message) {
            app(Recovery::class)->afterDeliveryFailure($message);
            app(Recovery::class)->afterDeliveryFailure($message);   // a retried webhook
        });

        $this->assertSame(1, $this->messages($lead)->where('channel', MessageChannel::Email)->count(), 'once, however often the provider says so');
        $log = AutomationActionLog::withoutGlobalScopes()->where('lead_id', $lead->id)->where('result', 'recovered')->first();
        $this->assertNotNull($log);
    }

    public function test_a_message_a_person_sent_failing_is_not_automations_to_recover(): void
    {
        $this->templates();
        $lead = $this->lead();
        $message = $this->failedMessage($lead, 'whatsapp', ['user_id' => $this->t['admin']->id]);

        $this->asTenant($this->t['tenant'], fn () => app(Recovery::class)->afterDeliveryFailure($message));

        $this->assertSame(0, $this->messages($lead)->where('channel', MessageChannel::Email)->count());
    }

    public function test_a_lead_a_person_already_has_is_not_written_to_by_a_recovery(): void
    {
        $this->templates();
        $lead = $this->lead(['automation_state' => Lead::AUTOMATION_HANDED_OFF]);
        $message = $this->failedMessage($lead, 'whatsapp');

        $this->asTenant($this->t['tenant'], fn () => app(Recovery::class)->afterDeliveryFailure($message));

        $this->assertSame(0, $this->messages($lead)->where('channel', MessageChannel::Email)->count());
    }

    public function test_a_failed_automated_message_with_no_known_twin_goes_to_a_person(): void
    {
        $lead = $this->lead();
        $message = $this->failedMessage($lead, 'whatsapp', ['template_name' => null]);

        $this->asTenant($this->t['tenant'], fn () => app(Recovery::class)->afterDeliveryFailure($message));

        $this->assertSame('delivery_failed', $this->asTenant($this->t['tenant'], fn () => $lead->fresh()->needs_human_reason));
    }

    // ------------------------------------------------------------- the hand-off freeze

    public function test_handing_a_lead_over_freezes_the_automated_messages_still_waiting_but_not_the_visit_reminders(): void
    {
        $lead = $this->lead(['automation_state' => Lead::AUTOMATION_NURTURING]);
        $mk = fn (array $action) => $this->asTenant($this->t['tenant'], fn () => ScheduledAction::create([
            'lead_id' => $lead->id, 'rule_name' => 'قاعدة', 'action' => $action, 'run_at' => now()->addHours(5), 'status' => ScheduledAction::PENDING,
        ]));
        $nurture = $mk(['type' => 'send_whatsapp', 'purpose' => 'nurture']);
        $ask = $mk(['type' => 'ask_qualification']);
        $visit = $mk(['type' => 'send_whatsapp', 'purpose' => 'appointment']);
        $task = $mk(['type' => 'create_call_task']);

        $result = $this->act($lead, ['type' => 'hand_off', 'reason' => 'replied']);

        $this->assertStringContainsString('جُمِّدت 2', $result['description']);
        $status = fn ($s) => ScheduledAction::withoutGlobalScopes()->find($s->id)->status;
        $this->assertSame(ScheduledAction::CANCELLED, $status($nurture));
        $this->assertSame(ScheduledAction::CANCELLED, $status($ask));
        $this->assertSame(ScheduledAction::PENDING, $status($visit), 'a visit reminder is service, not nurture');
        $this->assertSame(ScheduledAction::PENDING, $status($task), 'and a task is not a message');
    }

    // ------------------------------------------------------------- the brief

    public function test_a_lead_handed_to_a_rep_comes_with_everything_to_start_the_call(): void
    {
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
        $budgetQ = $this->asTenant($this->t['tenant'], fn () => ProjectQuestion::create(['project_id' => $project->id, 'key' => 'budget', 'label' => 'ما ميزانيتك؟', 'type' => 'number', 'is_active' => true, 'sort_order' => 1]));
        $this->asTenant($this->t['tenant'], fn () => ProjectQuestion::create(['project_id' => $project->id, 'key' => 'kids', 'label' => 'كم عدد أفراد الأسرة؟', 'type' => 'text', 'is_active' => true, 'sort_order' => 2]));
        $lead = $this->lead(['project_id' => $project->id, 'city' => 'الرياض', 'platform' => 'tiktok', 'campaign_name' => 'حملة الصيف']);
        $this->asTenant($this->t['tenant'], function () use ($lead, $budgetQ) {
            LeadAnswer::create(['lead_id' => $lead->id, 'project_question_id' => $budgetQ->id, 'value' => 1_500_000]);
            LeadMessage::create(['lead_id' => $lead->id, 'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Whatsapp, 'provider' => 'mock', 'message_type' => 'text', 'body' => 'أهلًا سعد، كيف نخدمك؟', 'status' => 'sent']);
            LeadMessage::create(['lead_id' => $lead->id, 'direction' => MessageDirection::Inbound, 'channel' => MessageChannel::Whatsapp, 'provider' => 'whatsapp_cloud', 'message_type' => 'text', 'body' => 'ابي فيلا وابي أزور الموقع بس السعر غالي', 'status' => 'received']);
            $lead->forceFill(['automation_state' => Lead::AUTOMATION_HANDED_OFF, 'needs_human_reason' => 'replied', 'handed_off_at' => now()->subMinutes(12), 'first_response_at' => now()->subMinutes(13)])->saveQuietly();
        });

        $brief = $this->actingAs($this->t['admin'])->getJson("/api/v1/leads/{$lead->uuid}")->assertOk()->json('data.handoff');

        $this->assertSame('replied', $brief['reason']['code']);
        $this->assertSame('النرجس', $brief['summary']['project']);
        $this->assertSame('حملة الصيف', $brief['summary']['campaign']);
        $this->assertSame([1, 2], [$brief['qualification']['done'], $brief['qualification']['total']]);
        $this->assertSame('ما ميزانيتك؟', $brief['qualification']['answered'][0]['label']);
        $this->assertSame(['كم عدد أفراد الأسرة؟'], $brief['qualification']['missing']);
        $this->assertContains('price_high', $brief['objections']);
        $this->assertCount(2, $brief['last_conversation']);
        $this->assertSame(['automation', 'customer'], array_column($brief['last_conversation'], 'who'));
        $this->assertNotNull($brief['next_action']);
        $this->assertContains('replied', $brief['why_now']['signals']);

        // How interested, and who spoke last: the two things the band shows without opening anything.
        $this->assertArrayHasKey('level', $brief['interest']);
        $this->assertSame('customer', $brief['last_activity']['who']);
        $this->assertNotNull($brief['last_activity']['at']);
    }

    public function test_a_lead_automation_still_has_carries_no_brief_and_the_queue_brings_it_for_the_lead_to_work(): void
    {
        $nurturing = $this->lead(['automation_state' => Lead::AUTOMATION_NURTURING]);
        $this->assertNull($this->actingAs($this->t['admin'])->getJson("/api/v1/leads/{$nurturing->uuid}")->json('data.handoff'));

        $handed = $this->lead();
        $this->asTenant($this->t['tenant'], fn () => $handed->forceFill(['automation_state' => Lead::AUTOMATION_HANDED_OFF, 'needs_human_reason' => 'qualified', 'handed_off_at' => now()])->saveQuietly());

        $next = $this->actingAs($this->t['admin'])->getJson('/api/v1/work/next')->assertOk()->json('data');
        $this->assertSame($handed->uuid, $next['lead']['id']);
        $this->assertSame('qualified', $next['handoff']['reason']['code']);
    }
}
