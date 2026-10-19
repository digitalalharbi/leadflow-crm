<?php

namespace Tests\Feature;

use App\DataTransferObjects\IncomingLead;
use App\Domain\Automation\JourneyInstaller;
use App\Domain\Automation\TemplatePicker;
use App\Domain\Leads\NextBestAction;
use App\Enums\IntegrationProvider;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Events\LeadQualified;
use App\Jobs\RunScheduledAction;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\ProjectQuestion;
use App\Models\ScheduledAction;
use App\Services\Leads\LeadIngestionService;
use App\Services\Whatsapp\InboundWhatsappProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Automation first, people by exception.
 *
 * A lead arrives and the conversation starts without anybody: a welcome on
 * WhatsApp and by email, the brochure, the project's own questions, a reminder
 * if they go quiet. A rep sees the lead when it is a person's turn — the
 * customer answered, qualified, stayed silent through all of it, or nothing
 * could reach them — and the queue says which.
 */
class NoLeadWaitsForAPersonTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Project $project;

    private Integration $whatsapp;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();

        // The waiting steps are inspected, then run by hand: the test queue
        // runs a delayed job at once, which is not what a day's wait means.
        Queue::fake([RunScheduledAction::class]);

        $this->t = $this->provisionTenant('Journey Co');

        $this->project = $this->asTenant($this->t['tenant'], function () {
            $project = Project::create(['name' => 'النرجس']);
            ProjectFile::create([
                'project_id' => $project->id, 'name' => 'بروشور النرجس', 'kind' => 'brochure',
                'path' => 'project-files/'.uniqid().'.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1024, 'is_active' => true,
            ]);
            ProjectQuestion::create(['project_id' => $project->id, 'key' => 'budget', 'label' => 'ما ميزانيتك التقريبية؟', 'type' => 'text', 'is_active' => true, 'sort_order' => 1]);
            ProjectQuestion::create(['project_id' => $project->id, 'key' => 'timeline', 'label' => 'متى تنوي الشراء؟', 'type' => 'text', 'is_active' => true, 'sort_order' => 2]);

            return $project;
        });
    }

    /** A WhatsApp Business number with a token: the Cloud API, not the mock. */
    private function connectWhatsapp(): void
    {
        $this->whatsapp = $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'whatsapp',
            'name' => 'WhatsApp Business',
            'status' => Integration::CONNECTED,
            'config' => ['phone_number_id' => '1555000111'],
            'credentials' => ['access_token' => 'token', 'verify_token' => 'v', 'app_secret' => 's'],
            'is_active' => true,
        ]));

        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]], 200)]);

        // Outside the 24-hour window a first message has to be an approved
        // template; the workspace registered its welcome as one.
        $this->asTenant($this->t['tenant'], fn () => MessageTemplate::where('channel', 'whatsapp')->whereIn('purpose', ['welcome', 'reminder', 'qualification'])
            ->update(['meta_template_name' => 'welcome_v1', 'meta_language' => 'ar', 'meta_parameters' => []]));
    }

    /** A real mailer — not the log driver, which only pretends. */
    private function connectEmail(): void
    {
        config(['mail.default' => 'smtp']);
    }

    private function arrive(array $data = []): Lead
    {
        $result = app(LeadIngestionService::class)->ingest($this->t['tenant'], IncomingLead::fromArray(IntegrationProvider::GoogleSheets, $data + [
            'full_name' => 'سلمان القحطاني',
            'phone' => '+966551234567',
            'email' => 'salman@example.com',
            'project_id' => $this->project->id,
            'campaign_name' => 'النرجس — أكتوبر',
            'platform' => 'meta',
        ]));

        return $this->asTenant($this->t['tenant'], fn () => $result->lead->fresh());
    }

    private function queue(): array
    {
        return $this->actingAs($this->t['admin'])->getJson('/api/v1/work/next')->assertOk()->json('data');
    }

    private function pending(Lead $lead)
    {
        return ScheduledAction::withoutGlobalScopes()->where('lead_id', $lead->id)->where('status', ScheduledAction::PENDING)->get();
    }

    private function reply(Lead $lead, string $body = 'نعم مهتم'): void
    {
        $this->asTenant($this->t['tenant'], fn () => app(InboundWhatsappProcessor::class)->process($this->whatsapp, [
            'entry' => [['changes' => [['value' => [
                'metadata' => ['phone_number_id' => '1555000111'],
                'contacts' => [['wa_id' => '966551234567']],
                'messages' => [['from' => '966551234567', 'id' => 'wamid.in.'.uniqid(), 'type' => 'text', 'text' => ['body' => $body], 'timestamp' => (string) time()]],
            ]]]]],
        ]));
    }

    // ------------------------------------------------------------ the journey

    public function test_every_workspace_starts_with_the_journey_switched_on(): void
    {
        $rules = $this->asTenant($this->t['tenant'], fn () => AutomationRule::whereIn('template_key', JourneyInstaller::RULES)->get());

        $this->assertCount(3, $rules);
        $this->assertTrue($rules->every->is_enabled);
        $this->assertTrue($this->asTenant($this->t['tenant'], fn () => MessageTemplate::where('purpose', 'qualification')->exists()));
    }

    public function test_a_new_lead_is_welcomed_sent_the_brochure_and_asked_its_questions_with_nobody_involved(): void
    {
        $this->connectWhatsapp();
        $this->connectEmail();

        $lead = $this->arrive();

        $outbound = $this->asTenant($this->t['tenant'], fn () => LeadMessage::where('lead_id', $lead->id)->where('direction', MessageDirection::Outbound->value)->get());

        // Welcome on WhatsApp (as the approved template), welcome by email, and the brochure link.
        $this->assertTrue($outbound->contains(fn ($m) => $m->channel === MessageChannel::Whatsapp && $m->template_name === 'welcome_v1'));
        $this->assertTrue($outbound->contains(fn ($m) => $m->channel === MessageChannel::Email && str_contains((string) $m->subject, 'النرجس')));
        $this->assertNull($outbound->firstWhere('user_id', '!=', null), 'nothing was sent in a person\'s name');

        // Automation is looking after them, and the clock on first contact stopped.
        $this->assertSame(Lead::AUTOMATION_NURTURING, $lead->automation_state);
        $this->assertNotNull($lead->first_contact_at);

        // What is still to come, each step void the moment they reply.
        $waiting = $this->pending($lead);
        $this->assertEqualsCanonicalizing(
            ['ask_qualification', 'send_whatsapp', 'send_email', 'hand_off'],
            $waiting->map(fn ($s) => $s->action['type'])->all(),
        );
        $this->assertTrue($waiting->every(fn ($s) => ($s->action['unless'] ?? null) === 'replied'));

        // So no rep is asked to do what automation is already doing.
        $this->assertNull($this->queue()['lead']);
    }

    public function test_the_questions_asked_are_the_projects_own_numbered(): void
    {
        $this->connectWhatsapp();
        $lead = $this->arrive();

        $ask = $this->pending($lead)->first(fn ($s) => $s->action['type'] === 'ask_qualification');
        app()->call([new RunScheduledAction($ask->id), 'handle']);

        $sent = $this->asTenant($this->t['tenant'], fn () => LeadMessage::where('lead_id', $lead->id)->latest('id')->first());
        $this->assertStringContainsString("1. ما ميزانيتك التقريبية؟\n2. متى تنوي الشراء؟", $sent->body);
        $this->assertSame(ScheduledAction::DONE, $ask->fresh()->status);
    }

    public function test_a_reply_hands_the_lead_to_a_person_and_stops_every_reminder(): void
    {
        $this->connectWhatsapp();
        $lead = $this->arrive();
        $scoreBefore = $lead->score;

        $this->reply($lead);

        $lead = $this->asTenant($this->t['tenant'], fn () => $lead->fresh());
        $this->assertSame(Lead::AUTOMATION_HANDED_OFF, $lead->automation_state);
        $this->assertSame('replied', $lead->needs_human_reason);
        $this->assertGreaterThan($scoreBefore, $lead->score);

        // Nothing that was waiting for silence is still waiting.
        $this->assertCount(0, $this->pending($lead));

        // And it is the first thing the rep sees, with the reason.
        $queue = $this->queue();
        $this->assertSame($lead->uuid, $queue['lead']['id']);
        $this->assertSame('reply_now', $queue['action']['action']);
        $this->assertSame('critical', $queue['action']['urgency']);
        $this->assertStringContainsString('أكمل نفس المحادثة', $queue['action']['reason']);
    }

    public function test_a_reply_that_races_a_reminder_still_stops_it(): void
    {
        $this->connectWhatsapp();
        $lead = $this->arrive();
        $reminder = $this->pending($lead)->first(fn ($s) => $s->action['type'] === 'send_whatsapp');

        // The reply lands without the cancellation having run (another worker).
        $this->asTenant($this->t['tenant'], fn () => LeadMessage::create([
            'lead_id' => $lead->id, 'direction' => MessageDirection::Inbound, 'channel' => MessageChannel::Whatsapp,
            'provider' => 'whatsapp_cloud', 'provider_message_id' => 'wamid.race', 'message_type' => 'text', 'body' => 'نعم', 'status' => 'received',
        ]));

        $before = $this->asTenant($this->t['tenant'], fn () => LeadMessage::where('lead_id', $lead->id)->count());
        app()->call([new RunScheduledAction($reminder->id), 'handle']);

        $this->assertSame(ScheduledAction::CANCELLED, $reminder->fresh()->status);
        $this->assertSame($before, $this->asTenant($this->t['tenant'], fn () => LeadMessage::where('lead_id', $lead->id)->count()));
    }

    public function test_silence_through_the_whole_journey_is_a_person_s_turn(): void
    {
        $this->connectWhatsapp();
        $lead = $this->arrive();

        $handOff = $this->pending($lead)->first(fn ($s) => $s->action['type'] === 'hand_off');
        app()->call([new RunScheduledAction($handOff->id), 'handle']);

        $lead = $this->asTenant($this->t['tenant'], fn () => $lead->fresh());
        $this->assertSame('no_reply', $lead->needs_human_reason);

        $queue = $this->queue();
        $this->assertSame($lead->uuid, $queue['lead']['id']);
        $this->assertStringContainsString('لم يرد على الرسائل الآلية', $queue['action']['reason']);
    }

    public function test_a_lead_nothing_could_reach_goes_straight_to_a_person(): void
    {
        // No WhatsApp connected, and the log mailer only pretends to send.
        $lead = $this->arrive();

        $this->assertSame(Lead::AUTOMATION_HANDED_OFF, $lead->automation_state);
        $this->assertSame('unreached', $lead->needs_human_reason);
        $this->assertSame($lead->uuid, $this->queue()['lead']['id']);
    }

    public function test_a_lead_that_turns_hot_while_nurtured_is_shown_to_a_rep(): void
    {
        $this->connectWhatsapp();
        $lead = $this->arrive();
        $this->assertNull($this->queue()['lead']);

        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['temperature' => 'hot'])->saveQuietly());

        $this->assertSame($lead->uuid, $this->queue()['lead']['id']);
    }

    public function test_a_qualified_lead_becomes_a_priority_and_a_person_s_turn(): void
    {
        $this->connectWhatsapp();
        $lead = $this->arrive();

        $this->asTenant($this->t['tenant'], fn () => LeadQualified::dispatch($lead->fresh()));

        $lead = $this->asTenant($this->t['tenant'], fn () => $lead->fresh());
        $this->assertTrue($lead->is_priority);
        $this->assertSame('qualified', $lead->needs_human_reason);

        $action = app(NextBestAction::class)->for($lead);
        $this->assertSame('call_now', $action['action']);
        $this->assertSame('critical', $action['urgency']);
    }

    // ------------------------------------------------------------ the right message

    public function test_each_lead_gets_the_message_written_for_its_project_and_language(): void
    {
        [$general, $project, $english, $otherProject] = $this->asTenant($this->t['tenant'], function () {
            $other = Project::create(['name' => 'الواحة']);

            return [
                MessageTemplate::where('channel', 'whatsapp')->where('purpose', 'welcome')->firstOrFail(),
                MessageTemplate::create(['name' => 'ترحيب النرجس', 'body' => 'أهلًا في النرجس', 'channel' => 'whatsapp', 'purpose' => 'welcome', 'project_id' => $this->project->id, 'is_active' => true]),
                MessageTemplate::create(['name' => 'Narjis welcome', 'body' => 'Welcome', 'channel' => 'whatsapp', 'purpose' => 'welcome', 'project_id' => $this->project->id, 'language' => 'en', 'is_active' => true]),
                MessageTemplate::create(['name' => 'ترحيب الواحة', 'body' => 'أهلًا في الواحة', 'channel' => 'whatsapp', 'purpose' => 'welcome', 'project_id' => $other->id, 'is_active' => true]),
            ];
        });

        $picker = app(TemplatePicker::class);

        $saudi = $this->asTenant($this->t['tenant'], fn () => Lead::create(['full_name' => 'أ', 'phone' => '+966550000001', 'phone_normalized' => '+966550000001', 'project_id' => $this->project->id]));
        $british = $this->asTenant($this->t['tenant'], fn () => Lead::create(['full_name' => 'B', 'phone' => '+447700900123', 'phone_normalized' => '+447700900123', 'project_id' => $this->project->id]));
        $noProject = $this->asTenant($this->t['tenant'], fn () => Lead::create(['full_name' => 'ج', 'phone' => '+966550000002', 'phone_normalized' => '+966550000002']));

        $this->asTenant($this->t['tenant'], function () use ($picker, $saudi, $british, $noProject, $general, $project, $english, $otherProject) {
            $this->assertSame($project->id, $picker->pick($saudi, 'whatsapp', 'welcome')->id);
            $this->assertSame($english->id, $picker->pick($british, 'whatsapp', 'welcome')->id);
            $this->assertSame($general->id, $picker->pick($noProject, 'whatsapp', 'welcome')->id);
            $this->assertNotSame($otherProject->id, $picker->pick($noProject, 'whatsapp', 'welcome')->id);
        });
    }

    // ------------------------------------------------------------ the workspace's decision

    public function test_a_workspace_that_deleted_its_journey_keeps_it_deleted(): void
    {
        $this->asTenant($this->t['tenant'], fn () => AutomationRule::where('template_key', 'lead_journey')->delete());

        $this->asTenant($this->t['tenant'], fn () => app(JourneyInstaller::class)->install($this->t['tenant']));

        $this->assertFalse($this->asTenant($this->t['tenant'], fn () => AutomationRule::where('template_key', 'lead_journey')->exists()));

        // And can have it back from the gallery whenever it wants.
        $this->actingAs($this->t['admin'])->postJson('/api/v1/automation/templates/lead_journey')
            ->assertCreated()
            ->assertJsonPath('data.rule.is_enabled', false);
    }

    public function test_a_journey_step_without_a_message_for_its_purpose_says_so(): void
    {
        $this->connectWhatsapp();
        $this->asTenant($this->t['tenant'], fn () => MessageTemplate::where('purpose', 'welcome')->update(['is_active' => false]));

        $lead = $this->arrive();

        $run = $this->asTenant($this->t['tenant'], fn () => AutomationRun::where('lead_id', $lead->id)->latest('id')->firstWhere('automation_rule_id', AutomationRule::where('template_key', 'lead_journey')->value('id')));
        $this->assertStringContainsString('لا توجد رسالة ترحيب على واتساب', json_encode($run->applied_actions, JSON_UNESCAPED_UNICODE));
    }
}
