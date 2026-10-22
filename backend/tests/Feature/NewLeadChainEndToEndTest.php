<?php

namespace Tests\Feature;

use App\Domain\Sla\SlaSweeper;
use App\Jobs\RunScheduledAction;
use App\Models\AutomationRule;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadSourceEvent;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\ProjectQuestion;
use App\Models\ScheduledAction;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The whole arrival, from the platform's POST to the salesperson's phone, with nobody touching it:
 *
 *   lead created → normalised → de-duplicated → attributed → in its project → assigned → the journey
 *   (WhatsApp welcome, email, brochure, the project's questions) → the owner told → the SLA clock running.
 *
 * The pieces each have their own tests; this is the one that proves they are one chain.
 */
class NewLeadChainEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'chain-secret-0123456789abcdefghij';

    private array $t;

    private Project $project;

    private WebhookEndpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // The email goes through the product's own door, configured; WhatsApp is the Cloud API.
        config(['leadflow_mail.provider' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.mailers.smtp.username' => 'user']);
        Queue::fake([RunScheduledAction::class]);

        $this->t = $this->provisionTenant('Chain Realty');
        $rep = $this->makeUser($this->t['tenant']);
        // The workspace has chosen to hand its leads out; a workspace that has not leaves them to its managers.
        $this->t['tenant']->settings->forceFill(['assignment_mode' => 'round_robin'])->save();

        $this->project = $this->asTenant($this->t['tenant'], function () {
            $project = Project::create(['name' => 'النرجس']);
            ProjectFile::create(['project_id' => $project->id, 'name' => 'بروشور النرجس', 'kind' => 'brochure', 'path' => 'project-files/'.uniqid().'.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1024, 'is_active' => true]);
            ProjectQuestion::create(['project_id' => $project->id, 'key' => 'budget', 'label' => 'ما ميزانيتك التقريبية؟', 'type' => 'text', 'is_active' => true, 'sort_order' => 1]);
            Integration::create(['provider' => 'whatsapp', 'name' => 'WhatsApp Business', 'status' => Integration::CONNECTED, 'config' => ['phone_number_id' => '1555000111'], 'credentials' => ['access_token' => 'token', 'verify_token' => 'v', 'app_secret' => 's'], 'is_active' => true]);
            MessageTemplate::where('channel', 'whatsapp')->whereIn('purpose', ['welcome', 'reminder', 'qualification'])->update(['meta_template_name' => 'welcome_v1', 'meta_language' => 'ar', 'meta_parameters' => []]);

            return $project;
        });
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]], 200)]);

        $this->endpoint = $this->asTenant($this->t['tenant'], function () {
            $e = WebhookEndpoint::where('tenant_id', $this->t['tenant']->id)->first();
            $e->forceFill(['secret_hash' => Hash::make(self::SECRET)])->saveQuietly();
            // The project a form belongs to is the workspace's setting on the connection — never the payload's say.
            $e->integration?->forceFill(['defaults' => ['project_id' => $this->project->id]])->saveQuietly();

            return $e;
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function arrive(array $extra = []): void
    {
        $this->withHeader('X-Webhook-Secret', self::SECRET)
            ->postJson("/api/v1/integrations/webhooks/{$this->endpoint->key}/leads", $extra + [
                'full_name' => 'سلطان المطيري', 'phone' => '0551234567', 'email' => 'sultan@example.com',
                'platform' => 'meta', 'campaign_name' => 'النرجس — أكتوبر',
            ])->assertSuccessful();
    }

    public function test_one_post_starts_the_whole_conversation_and_tells_the_owner(): void
    {
        $this->arrive();

        $this->asTenant($this->t['tenant'], function () {
            // Created, normalised, attributed, in its project.
            $lead = Lead::where('phone_normalized', '+966551234567')->firstOrFail();
            $this->assertSame($this->project->id, $lead->project_id);
            $this->assertSame('النرجس — أكتوبر', $lead->campaign_name);
            $this->assertDatabaseHas('lead_source_events', ['lead_id' => $lead->id]);

            // Assigned, and the owner told in the app — nobody was asked to look.
            $this->assertNotNull($lead->assigned_to, 'nobody owns it');
            $told = DatabaseNotification::where('notifiable_id', $lead->assigned_to)->get()->filter(fn ($n) => ($n->data['lead_id'] ?? null) === $lead->uuid);
            $this->assertCount(1, $told, 'the owner is told once');
            $this->assertStringContainsString('النرجس', $told->first()->data['body']);

            // The conversation began by itself: WhatsApp, the email, the brochure.
            $sent = LeadMessage::where('lead_id', $lead->id)->where('direction', 'outbound')->get();
            $this->assertTrue($sent->contains(fn ($m) => ($m->channel->value ?? $m->channel) === 'whatsapp'), 'no WhatsApp went');
            $this->assertTrue($sent->contains(fn ($m) => ($m->channel->value ?? $m->channel) === 'email'), 'no email went');
            $this->assertDatabaseHas('file_shares', ['lead_id' => $lead->id]);

            // The project's own question is waiting, and the lead was not left to a person.
            $waiting = ScheduledAction::where('lead_id', $lead->id)->where('status', ScheduledAction::PENDING)->get();
            $this->assertTrue($waiting->contains(fn ($s) => ($s->action['type'] ?? '') === 'ask_qualification'), 'the project\'s questions are not scheduled');

            // The first contact was made by the journey, so the clock for a person never started running.
            $this->assertNotNull($lead->fresh()->first_contact_at, 'nothing counts as the first contact');
        });
    }

    public function test_the_same_customer_posted_twice_is_one_lead_and_the_journey_does_not_start_again(): void
    {
        $this->arrive();
        $messages = $this->asTenant($this->t['tenant'], fn () => LeadMessage::where('direction', 'outbound')->count());

        // The same number in another spelling, from another form.
        $this->arrive(['phone' => '+966 55 123 4567', 'campaign_name' => 'النرجس — نموذج آخر']);

        $this->asTenant($this->t['tenant'], function () use ($messages) {
            $this->assertSame(1, Lead::where('phone_normalized', '+966551234567')->count(), 'a duplicate lead was made');
            $this->assertSame($messages, LeadMessage::where('direction', 'outbound')->count(), 'the customer was welcomed a second time');
            $this->assertGreaterThanOrEqual(2, LeadSourceEvent::where('lead_id', Lead::first()->id)->count(), 'the second arrival is kept as a source event');
        });
    }

    public function test_a_lead_nobody_contacted_still_has_its_clock_and_the_owner_is_reminded(): void
    {
        // A workspace with no journey messages at all: the person is the only one who can start, and the clock says so.
        $this->asTenant($this->t['tenant'], function () {
            AutomationRule::query()->delete();
            $this->t['tenant']->settings->forceFill(['sla_remind_after_minutes' => 10, 'sla_escalate_after_minutes' => 30, 'sla_reassign_after_minutes' => 0])->save();
        });

        $this->arrive(['email' => null]);
        $lead = $this->asTenant($this->t['tenant'], fn () => Lead::where('phone_normalized', '+966551234567')->firstOrFail());
        $this->assertNull($lead->first_contact_at);

        Carbon::setTestNow(now()->addMinutes(40));
        $this->asTenant($this->t['tenant'], fn () => app(SlaSweeper::class)->sweep($this->t['tenant']->fresh()));

        $reminders = $this->asTenant($this->t['tenant'], fn () => DatabaseNotification::where('notifiable_id', $lead->assigned_to)->get()->filter(fn ($n) => ($n->data['lead_id'] ?? null) === $lead->uuid && ($n->data['type'] ?? '') !== 'lead_assigned'));
        $this->assertGreaterThan(0, $reminders->count(), 'the owner was never reminded');
    }
}
