<?php

namespace Tests\Feature;

use App\Domain\Automation\ActionExecutor;
use App\Enums\ActivityType;
use App\Models\FileShare;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadMessage;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\ProjectFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A rule can contact the customer.
 *
 * Both mechanisms were already built and neither could be reached by anything
 * except a person pressing a button: `WhatsappSender` was used by one
 * controller, and `FileForTheLead` by one more. So "a qualification sequence"
 * and "files automation" existed as machinery and as nothing else — a rep doing
 * it by hand, twenty times a day, which is the work automation is for.
 *
 * These are allow-tests before they are deny-tests. A suite that only checks
 * the refusals passes just as happily when the action never runs at all, which
 * is exactly the state this file exists to leave behind.
 */
class ARuleCanReachTheCustomerTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->t = $this->provisionTenant('Reach Co');

        $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'whatsapp',
            'name' => 'WhatsApp Business',
            'status' => Integration::CONNECTED,
            'config' => ['phone_number_id' => '1555000999'],
            // No access token, so the manager reaches for the mock provider —
            // the same arrangement the rest of the WhatsApp tests use.
            'credentials' => ['verify_token' => 'v', 'app_secret' => 's'],
            'is_active' => true,
        ]));

        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'سلمان القحطاني',
            'phone' => '0551234567',
            'phone_normalized' => '+9665512'.random_int(10000, 99999),
            'status' => 'new',
            'project_id' => $this->project->id,
        ], $attributes)));
    }

    private function template(array $attributes = []): MessageTemplate
    {
        return $this->asTenant($this->t['tenant'], fn () => MessageTemplate::create(array_merge([
            // Not one of the names provisioning installs: a workspace comes
            // with four saved messages, and `(tenant_id, name)` is unique.
            'name' => 'تعريف القاعدة',
            'body' => 'مرحبًا {{name}}، معك فريق المبيعات.',
            'is_active' => true,
        ], $attributes)));
    }

    private function file(array $attributes = []): ProjectFile
    {
        return $this->asTenant($this->t['tenant'], fn () => ProjectFile::create(array_merge([
            'project_id' => $this->project->id,
            'name' => 'كتيب النرجس',
            'kind' => 'brochure',
            'language' => null,
            'path' => 'project-files/'.uniqid().'.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 1024,
        ], $attributes)));
    }

    /** Opting out is a timestamp, and it is not mass assignable. */
    private function optedOut(): Lead
    {
        $lead = $this->lead();

        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['whatsapp_opt_out_at' => now()])->save());

        return $lead;
    }

    private function fire(Lead $lead, array $action): array
    {
        return $this->asTenant($this->t['tenant'], fn () => app(ActionExecutor::class)->execute($lead, $action));
    }

    // ------------------------------------------------------------ messaging

    public function test_a_rule_sends_the_workspaces_own_message(): void
    {
        $lead = $this->lead();
        $template = $this->template();

        $result = $this->fire($lead, ['type' => 'send_whatsapp', 'template_uuid' => $template->uuid]);

        $this->assertTrue($result['ok'], $result['description']);

        $message = LeadMessage::withoutGlobalScopes()->where('lead_id', $lead->id)->first();

        $this->assertNotNull($message, 'the rule reported a send and sent nothing');
        // Rendered for this lead, not stored with the placeholder in it. The
        // renderer uses the first name, which is how anyone here is addressed.
        $this->assertStringContainsString('سلمان', $message->body);
        $this->assertStringNotContainsString('{{name}}', $message->body);
        // The template is named on the message, so "which wording went out" is
        // answerable later.
        $this->assertSame('تعريف القاعدة', $message->template_name);
    }

    /**
     * A rule is not a person.
     *
     * Attributing an automated message to the lead's owner would put their name
     * on something they did not write, in the interface and in the audit trail.
     */
    public function test_an_automated_message_is_not_attributed_to_a_rep(): void
    {
        $lead = $this->lead(['assigned_to' => $this->t['admin']->id]);

        $this->fire($lead, ['type' => 'send_whatsapp', 'template_uuid' => $this->template()->uuid]);

        $message = LeadMessage::withoutGlobalScopes()->where('lead_id', $lead->id)->first();

        $this->assertNull($message->user_id);
    }

    public function test_the_message_reaches_the_timeline(): void
    {
        $lead = $this->lead();

        $this->fire($lead, ['type' => 'send_whatsapp', 'template_uuid' => $this->template()->uuid]);

        $this->assertTrue(
            LeadActivity::withoutGlobalScopes()
                ->where('lead_id', $lead->id)
                ->where('type', ActivityType::WhatsappSent->value)
                ->exists(),
            'the send left no trace on the lead',
        );
    }

    /**
     * The refusals a workspace can act on, reported rather than thrown.
     *
     * One action failing must not abandon the rest of the rule, and the reason
     * has to survive into the trace a manager reads when they ask why a
     * customer did not hear from us.
     */
    public function test_a_lead_who_opted_out_is_not_messaged_by_a_rule(): void
    {
        $lead = $this->optedOut();

        $result = $this->fire($lead, ['type' => 'send_whatsapp', 'template_uuid' => $this->template()->uuid]);

        $this->assertFalse($result['ok']);
        $this->assertSame(0, LeadMessage::withoutGlobalScopes()->where('lead_id', $lead->id)->count());
    }

    public function test_a_rule_pointing_at_a_message_that_is_gone_says_so(): void
    {
        $result = $this->fire($this->lead(), [
            'type' => 'send_whatsapp',
            'template_uuid' => '00000000-0000-4000-8000-000000000000',
        ]);

        $this->assertFalse($result['ok']);
    }

    public function test_a_paused_message_is_not_sent(): void
    {
        $lead = $this->lead();
        $template = $this->template(['is_active' => false]);

        $result = $this->fire($lead, ['type' => 'send_whatsapp', 'template_uuid' => $template->uuid]);

        $this->assertFalse($result['ok']);
        $this->assertSame(0, LeadMessage::withoutGlobalScopes()->where('lead_id', $lead->id)->count());
    }

    // ---------------------------------------------------------------- files

    public function test_a_rule_hands_the_lead_the_file_their_answers_point_at(): void
    {
        $this->file();
        $deck = $this->file(['kind' => 'investment_deck', 'name' => 'العرض الاستثماري']);
        $lead = $this->lead(['purchase_purpose' => 'investment']);

        $result = $this->fire($lead, ['type' => 'share_file']);

        $this->assertTrue($result['ok'], $result['description']);

        $share = FileShare::withoutGlobalScopes()->where('lead_id', $lead->id)->first();

        $this->assertNotNull($share);
        $this->assertSame($deck->id, $share->project_file_id, 'an investor was sent the brochure');
        $this->assertSame('automation', $share->channel);

        // Delivered, with the link in the message.
        $message = LeadMessage::withoutGlobalScopes()->where('lead_id', $lead->id)->first();
        $this->assertNotNull($message, 'the file was prepared and never sent');
        $this->assertStringContainsString($share->token, $message->body);
    }

    public function test_the_hand_off_reaches_the_timeline_once(): void
    {
        $this->file();
        $lead = $this->lead();

        $this->fire($lead, ['type' => 'share_file']);
        $this->fire($lead, ['type' => 'share_file']);

        $this->assertSame(
            1,
            LeadActivity::withoutGlobalScopes()
                ->where('lead_id', $lead->id)
                ->where('type', ActivityType::FileShared->value)
                ->count(),
            'a re-fired rule repeated the hand-off on the timeline',
        );

        // And one share, not two links whose opens have to be added up.
        $this->assertSame(1, FileShare::withoutGlobalScopes()->where('lead_id', $lead->id)->count());
    }

    public function test_a_rule_can_prepare_the_link_without_sending_it(): void
    {
        $this->file();
        $lead = $this->lead();

        $result = $this->fire($lead, ['type' => 'share_file', 'send' => false]);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, FileShare::withoutGlobalScopes()->where('lead_id', $lead->id)->count());
        $this->assertSame(0, LeadMessage::withoutGlobalScopes()->where('lead_id', $lead->id)->count());
    }

    /**
     * The link survives a delivery that does not.
     *
     * A workspace whose WhatsApp is not connected still gets the share, on the
     * lead's screen, for a rep to send by hand — which is strictly better than
     * having neither, and the trace says that is what happened.
     */
    public function test_a_failed_delivery_still_leaves_the_link(): void
    {
        $this->file();
        $lead = $this->optedOut();

        $result = $this->fire($lead, ['type' => 'share_file']);

        $this->assertFalse($result['ok']);
        $this->assertSame(1, FileShare::withoutGlobalScopes()->where('lead_id', $lead->id)->count());
    }

    public function test_a_project_with_no_files_reports_rather_than_sending_nothing_quietly(): void
    {
        $lead = $this->lead();

        $result = $this->fire($lead, ['type' => 'share_file']);

        $this->assertFalse($result['ok']);
        $this->assertSame(0, LeadMessage::withoutGlobalScopes()->where('lead_id', $lead->id)->count());
    }

    // ------------------------------------------------------------ the rule

    /**
     * The parameters survive being saved.
     *
     * `validate()` returns only the keys it has rules for, so a parameter
     * missing from the validator is dropped between the editor and the
     * database — silently, after a success toast. Every action here has been
     * through that once; this is the round trip that stops it.
     */
    public function test_a_rule_keeps_what_these_actions_need(): void
    {
        $template = $this->template();

        $response = $this->actingAs($this->t['admin'])->postJson('/api/v1/automation/rules', [
            'name' => 'تسلسل التأهيل',
            'trigger' => 'lead.created',
            'conditions' => [],
            'actions' => [
                ['type' => 'send_whatsapp', 'template_uuid' => $template->uuid, 'delay_minutes' => 30],
                ['type' => 'share_file', 'kind' => 'brochure', 'send' => false],
            ],
        ]);

        $response->assertCreated();

        $actions = $response->json('data.rule.actions');

        $this->assertSame($template->uuid, $actions[0]['template_uuid']);
        $this->assertSame(30, $actions[0]['delay_minutes']);
        $this->assertSame('brochure', $actions[1]['kind']);
        $this->assertFalse($actions[1]['send']);
    }

    public function test_a_message_action_with_no_message_is_refused_at_the_point_it_is_written(): void
    {
        $this->actingAs($this->t['admin'])->postJson('/api/v1/automation/rules', [
            'name' => 'بلا رسالة',
            'trigger' => 'lead.created',
            'conditions' => [],
            'actions' => [['type' => 'send_whatsapp']],
        ])->assertStatus(422);
    }
}
