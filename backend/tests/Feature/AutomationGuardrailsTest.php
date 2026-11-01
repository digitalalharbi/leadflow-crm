<?php

namespace Tests\Feature;

use App\Domain\Automation\ActionExecutor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Jobs\RunScheduledAction;
use App\Models\FileShare;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\ScheduledAction;
use App\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What automation may not do to a person, whatever a rule says.
 *
 * The allow cases come first on purpose: a suite of refusals passes just as
 * happily when nothing ever sends.
 */
class AutomationGuardrailsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake([RunScheduledAction::class]);

        $this->t = $this->provisionTenant('Guard Co');
        $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'whatsapp', 'name' => 'WhatsApp Business', 'status' => Integration::CONNECTED,
            'config' => ['phone_number_id' => '1555000999'], 'credentials' => ['verify_token' => 'v', 'app_secret' => 's'], 'is_active' => true,
        ]));
        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(array $attributes): void
    {
        $this->asTenant($this->t['tenant'], fn () => TenantSetting::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->firstOrFail()->update($attributes + ['timezone' => 'UTC']));
    }

    private function lead(array $attributes = []): Lead
    {
        $guarded = array_intersect_key($attributes, array_flip(['automation_state', 'needs_human_reason', 'handed_off_at']));

        return $this->asTenant($this->t['tenant'], function () use ($attributes, $guarded) {
            $lead = Lead::create(array_merge([
                'full_name' => 'سلمان القحطاني', 'phone' => '0551234567',
                'phone_normalized' => '+9665512'.self::uniqueDigits(5),
                'status' => 'new', 'project_id' => $this->project->id,
            ], array_diff_key($attributes, $guarded)));

            if ($guarded) {
                $lead->forceFill($guarded)->save();
            }

            return $lead;
        });
    }

    private function template(string $name = 'تعريف الحواجز'): MessageTemplate
    {
        return $this->asTenant($this->t['tenant'], fn () => MessageTemplate::create([
            'name' => $name, 'body' => 'مرحبًا {{name}}', 'is_active' => true,
        ]));
    }

    private function act(Lead $lead, array $action): array
    {
        return $this->asTenant($this->t['tenant'], fn () => app(ActionExecutor::class)->execute($lead, $action));
    }

    private function sendAction(MessageTemplate $template): array
    {
        return ['type' => 'send_whatsapp', 'template_uuid' => $template->uuid];
    }

    private function outbound(Lead $lead, array $attributes = []): LeadMessage
    {
        return $this->asTenant($this->t['tenant'], fn () => LeadMessage::create(array_merge([
            'lead_id' => $lead->id, 'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Whatsapp,
            'provider' => 'mock', 'message_type' => 'text', 'body' => 'x', 'status' => 'sent', 'sent_at' => now(),
        ], $attributes)));
    }

    // ------------------------------------------------------------ allow first

    public function test_with_no_limits_in_the_way_the_message_goes(): void
    {
        $template = $this->template();
        $lead = $this->lead();

        $result = $this->act($lead, $this->sendAction($template));

        $this->assertTrue($result['ok'], $result['description']);
        $this->assertSame(1, $this->asTenant($this->t['tenant'], fn () => LeadMessage::where('lead_id', $lead->id)->count()));
    }

    // ------------------------------------------------------------ quiet hours

    public function test_inside_quiet_hours_the_message_waits_until_they_end(): void
    {
        $this->settings(['quiet_hours_enabled' => true, 'quiet_hours_start' => '21:00', 'quiet_hours_end' => '08:00']);
        Carbon::setTestNow(Carbon::parse('2026-10-23 23:30:00', 'UTC'));
        $template = $this->template();
        $lead = $this->lead();

        $result = $this->act($lead, $this->sendAction($template));

        $this->assertSame('waiting', $result['result']);
        $this->assertSame(0, $this->asTenant($this->t['tenant'], fn () => LeadMessage::where('lead_id', $lead->id)->count()), 'nothing was sent');

        $waiting = ScheduledAction::withoutGlobalScopes()->where('lead_id', $lead->id)->sole();
        $this->assertSame('2026-10-24 08:00:00', $waiting->run_at->format('Y-m-d H:i:s'));
        Queue::assertPushed(RunScheduledAction::class);
    }

    public function test_the_early_morning_side_of_a_window_that_crosses_midnight_is_quiet_too(): void
    {
        $this->settings(['quiet_hours_enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-10-24 03:00:00', 'UTC'));

        $result = $this->act($this->lead(), $this->sendAction($this->template()));

        $this->assertSame('waiting', $result['result']);
    }

    public function test_outside_quiet_hours_nothing_is_held(): void
    {
        $this->settings(['quiet_hours_enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-10-23 12:00:00', 'UTC'));

        $this->assertTrue($this->act($this->lead(), $this->sendAction($this->template()))['ok']);
    }

    public function test_a_project_can_set_its_own_quiet_hours(): void
    {
        $this->settings(['quiet_hours_enabled' => false]);
        $this->asTenant($this->t['tenant'], fn () => $this->project->update(['automation_settings' => ['quiet_hours' => ['enabled' => true, 'start' => '12:00', 'end' => '14:00']]]));
        Carbon::setTestNow(Carbon::parse('2026-10-23 13:00:00', 'UTC'));

        $this->assertSame('waiting', $this->act($this->lead(), $this->sendAction($this->template()))['result']);
    }

    // ------------------------------------------------------------- repetition

    public function test_the_same_message_is_not_sent_twice(): void
    {
        $template = $this->template();
        $lead = $this->lead();

        $this->assertTrue($this->act($lead, $this->sendAction($template))['ok']);
        $second = $this->act($lead, $this->sendAction($template));

        $this->assertSame('skipped', $second['result']);
        $this->assertSame(1, $this->asTenant($this->t['tenant'], fn () => LeadMessage::where('lead_id', $lead->id)->count()));
    }

    public function test_a_different_message_to_the_same_lead_is_fine(): void
    {
        $lead = $this->lead();

        $this->act($lead, $this->sendAction($this->template('الأولى')));

        $this->assertTrue($this->act($lead, $this->sendAction($this->template('الثانية')))['ok']);
    }

    public function test_the_brochure_is_not_sent_again(): void
    {
        $file = $this->asTenant($this->t['tenant'], fn () => ProjectFile::create([
            'project_id' => $this->project->id, 'name' => 'كتيب', 'kind' => 'brochure', 'path' => 'project-files/b.pdf',
            'mime' => 'application/pdf', 'size_bytes' => 10, 'is_active' => true,
        ]));
        $lead = $this->lead();
        $this->asTenant($this->t['tenant'], fn () => FileShare::create(['project_file_id' => $file->id, 'lead_id' => $lead->id, 'channel' => 'whatsapp', 'token' => str_repeat('a', 40), 'opens' => 0]));

        $result = $this->act($lead, ['type' => 'share_file', 'kind' => 'brochure']);

        $this->assertSame('skipped', $result['result']);
        $this->assertStringContainsString('سبق', $result['description']);
    }

    // -------------------------------------------------------------- frequency

    public function test_a_lead_is_not_messaged_more_than_the_daily_cap(): void
    {
        $this->settings(['max_contacts_per_day' => 2]);
        $lead = $this->lead();
        $this->outbound($lead);
        $this->outbound($lead);

        $result = $this->act($lead, $this->sendAction($this->template()));

        $this->assertSame('waiting', $result['result']);
        $this->assertStringContainsString('2', $result['description']);
    }

    public function test_a_persons_messages_do_not_count_toward_the_cap(): void
    {
        $this->settings(['max_contacts_per_day' => 1]);
        $lead = $this->lead();
        $this->outbound($lead, ['user_id' => $this->t['admin']->id]);

        $this->assertTrue($this->act($lead, $this->sendAction($this->template()))['ok']);
    }

    public function test_a_cooldown_spaces_automated_messages_apart(): void
    {
        $this->settings(['contact_cooldown_minutes' => 30]);
        $lead = $this->lead();
        $this->outbound($lead);

        $this->assertSame('waiting', $this->act($lead, $this->sendAction($this->template()))['result']);
    }

    // ---------------------------------------------------------------- takeover

    public function test_a_lead_a_person_has_taken_is_not_messaged_by_rules(): void
    {
        $lead = $this->lead(['automation_state' => Lead::AUTOMATION_HANDED_OFF]);

        $result = $this->act($lead, $this->sendAction($this->template()));

        $this->assertSame('skipped', $result['result']);
        $this->assertSame(0, $this->asTenant($this->t['tenant'], fn () => LeadMessage::where('lead_id', $lead->id)->count()));
    }

    public function test_a_rep_calling_cancels_what_automation_had_queued(): void
    {
        $lead = $this->lead(['automation_state' => Lead::AUTOMATION_NURTURING]);
        $step = $this->asTenant($this->t['tenant'], fn () => ScheduledAction::create([
            'lead_id' => $lead->id, 'rule_name' => 'قاعدة', 'action' => ['type' => 'send_whatsapp'], 'run_at' => now()->addDay(), 'status' => ScheduledAction::PENDING,
        ]));

        $this->actingAs($this->t['admin'])->postJson('/api/v1/work/outcome', ['lead_id' => $lead->uuid, 'outcome' => 'call_later'])->assertSuccessful();

        $this->assertSame(ScheduledAction::CANCELLED, ScheduledAction::withoutGlobalScopes()->find($step->id)->status);
        $this->assertSame(Lead::AUTOMATION_HANDED_OFF, $this->asTenant($this->t['tenant'], fn () => $lead->fresh()->automation_state));
    }

    public function test_a_rep_writing_on_whatsapp_takes_the_lead(): void
    {
        $lead = $this->lead(['automation_state' => Lead::AUTOMATION_NURTURING]);

        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$lead->uuid}/contact", ['channel' => 'call'])->assertSuccessful();

        $this->assertSame(Lead::AUTOMATION_HANDED_OFF, $this->asTenant($this->t['tenant'], fn () => $lead->fresh()->automation_state));
    }

    public function test_a_lead_automation_never_touched_is_left_alone_by_a_rep(): void
    {
        $lead = $this->lead();

        $this->actingAs($this->t['admin'])->postJson('/api/v1/work/outcome', ['lead_id' => $lead->uuid, 'outcome' => 'call_later'])->assertSuccessful();

        $this->assertNull($this->asTenant($this->t['tenant'], fn () => $lead->fresh()->automation_state));
    }

    // ------------------------------------------------------------------ resume

    public function test_marking_no_answer_gives_the_lead_back_to_automation(): void
    {
        $lead = $this->lead(['automation_state' => Lead::AUTOMATION_HANDED_OFF, 'needs_human_reason' => 'unreached', 'handed_off_at' => now()]);

        $this->actingAs($this->t['admin'])->postJson('/api/v1/work/outcome', ['lead_id' => $lead->uuid, 'outcome' => 'no_answer'])->assertSuccessful();

        $fresh = $this->asTenant($this->t['tenant'], fn () => $lead->fresh());
        $this->assertSame(Lead::AUTOMATION_NURTURING, $fresh->automation_state);
        $this->assertNull($fresh->needs_human_reason);

        $nurture = ScheduledAction::withoutGlobalScopes()->where('lead_id', $lead->id)->pending()->sole();
        $this->assertSame('start_nurture', $nurture->action['type']);
        $this->assertTrue($nurture->run_at->isFuture(), 'after a day, not at once');
    }

    public function test_a_lead_who_answered_recently_is_not_resumed_into_the_journey(): void
    {
        $lead = $this->lead(['automation_state' => Lead::AUTOMATION_HANDED_OFF, 'needs_human_reason' => 'replied', 'handed_off_at' => now()]);
        $this->outbound($lead, ['direction' => MessageDirection::Inbound, 'status' => 'received']);

        $this->actingAs($this->t['admin'])->postJson('/api/v1/work/outcome', ['lead_id' => $lead->uuid, 'outcome' => 'no_answer'])->assertSuccessful();

        $this->assertSame(Lead::AUTOMATION_HANDED_OFF, $this->asTenant($this->t['tenant'], fn () => $lead->fresh()->automation_state));
    }

    // ---------------------------------------------------------------- settings

    public function test_the_workspace_can_set_its_own_limits_over_the_api(): void
    {
        $this->actingAs($this->t['admin'])->patchJson('/api/v1/settings', [
            'quiet_hours_enabled' => true, 'quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:00',
            'max_contacts_per_day' => 4, 'contact_cooldown_minutes' => 10,
        ])->assertOk();

        $settings = $this->actingAs($this->t['admin'])->getJson('/api/v1/settings')->json('data.settings');

        $this->assertTrue($settings['quiet_hours_enabled']);
        $this->assertSame('22:00', $settings['quiet_hours_start']);
        $this->assertSame(4, $settings['max_contacts_per_day']);
        $this->assertSame(10, $settings['contact_cooldown_minutes']);
    }
}
