<?php

namespace Tests\Feature;

use App\DataTransferObjects\IncomingLead;
use App\Domain\Automation\NoReplySweeper;
use App\Domain\Automation\RuleTemplates;
use App\Enums\IntegrationProvider;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Events\LeadSignal;
use App\Jobs\RunScheduledAction;
use App\Models\AutomationActionLog;
use App\Models\AutomationRule;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\ProjectQuestion;
use App\Models\ScheduledAction;
use App\Services\Leads\LeadIngestionService;
use App\Services\Leads\LeadScoringService;
use App\Services\Whatsapp\InboundWhatsappProcessor;
use App\Support\RoutineClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Rules control, execution acts, the history proves it.
 *
 * Every step automation takes — or waits to take, or decides against — is a
 * row a manager can read: time, lead, rule, action, channel, before → after,
 * result. These tests hold that promise for the journey every lead runs, and
 * for the rule centre around it: modes, the new triggers, per-project scoring.
 */
class TheAutomationCentreTest extends TestCase
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
        Queue::fake([RunScheduledAction::class]);
        config(['mail.default' => 'smtp']);

        $this->t = $this->provisionTenant('Centre Co');

        $this->project = $this->asTenant($this->t['tenant'], function () {
            $project = Project::create(['name' => 'الريم']);
            ProjectFile::create(['project_id' => $project->id, 'name' => 'بروشور الريم', 'kind' => 'brochure', 'path' => 'project-files/'.uniqid().'.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1024, 'is_active' => true]);
            ProjectQuestion::create(['project_id' => $project->id, 'key' => 'budget', 'label' => 'ما ميزانيتك؟', 'type' => 'text', 'is_active' => true]);

            return $project;
        });

        $this->whatsapp = $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'whatsapp', 'name' => 'WhatsApp Business', 'status' => Integration::CONNECTED,
            'config' => ['phone_number_id' => '1555000222'], 'credentials' => ['access_token' => 'token', 'verify_token' => 'v', 'app_secret' => 's'], 'is_active' => true,
        ]));
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]], 200)]);

        $this->asTenant($this->t['tenant'], fn () => MessageTemplate::where('channel', 'whatsapp')->whereNotNull('purpose')
            ->update(['meta_template_name' => 'approved_v1', 'meta_language' => 'ar', 'meta_parameters' => []]));
    }

    private function arrive(array $data = [], IntegrationProvider $provider = IntegrationProvider::GoogleSheets): Lead
    {
        $result = app(LeadIngestionService::class)->ingest($this->t['tenant'], IncomingLead::fromArray($provider, $data + [
            'full_name' => 'أحمد السبيعي', 'phone' => '+966552223333', 'email' => 'ahmed@example.com', 'project_id' => $this->project->id,
        ]));

        return $this->asTenant($this->t['tenant'], fn () => $result->lead->fresh());
    }

    private function history(Lead $lead)
    {
        return $this->asTenant($this->t['tenant'], fn () => AutomationActionLog::where('lead_id', $lead->id)->orderBy('id')->get());
    }

    private function rule(array $attributes): AutomationRule
    {
        return $this->asTenant($this->t['tenant'], fn () => AutomationRule::create($attributes + ['is_enabled' => true, 'conditions' => []]));
    }

    // ------------------------------------------------------------ the history proves it

    public function test_every_step_of_the_journey_is_a_row_in_the_history(): void
    {
        $lead = $this->arrive();
        $rows = $this->history($lead)->where('rule_name', RuleTemplates::get('lead_journey')['name']);

        $done = $rows->where('result', AutomationActionLog::COMPLETED)->pluck('action_type')->all();
        $this->assertContains('send_whatsapp', $done);
        $this->assertContains('send_email', $done);
        $this->assertContains('share_file', $done);

        $this->assertSame('whatsapp', $rows->firstWhere('action_type', 'send_whatsapp')->channel);
        $this->assertSame('email', $rows->firstWhere('action_type', 'send_email')->channel);

        // The steps still to come are rows too, marked as waiting.
        $waiting = $rows->where('result', AutomationActionLog::WAITING);
        $this->assertEqualsCanonicalizing(['ask_qualification', 'send_whatsapp', 'send_email', 'hand_off'], $waiting->pluck('action_type')->values()->all());
        $this->assertTrue($waiting->every(fn ($row) => $row->scheduled_action_id !== null));
    }

    public function test_a_waiting_step_that_runs_updates_its_row_and_a_reply_skips_the_rest(): void
    {
        $lead = $this->arrive();
        $ask = ScheduledAction::withoutGlobalScopes()->where('lead_id', $lead->id)->get()->first(fn ($s) => $s->action['type'] === 'ask_qualification');

        app()->call([new RunScheduledAction($ask->id), 'handle']);
        $this->assertSame(AutomationActionLog::COMPLETED, $this->history($lead)->firstWhere('scheduled_action_id', $ask->id)->result);

        $this->asTenant($this->t['tenant'], fn () => app(InboundWhatsappProcessor::class)->process($this->whatsapp, ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '1555000222'],
            'messages' => [['from' => '966552223333', 'id' => 'wamid.in.1', 'type' => 'text', 'text' => ['body' => 'مهتم'], 'timestamp' => (string) time()]],
        ]]]]]]));

        $rows = $this->history($lead);
        $this->assertTrue($rows->where('result', AutomationActionLog::WAITING)->isEmpty(), 'nothing still waits once they replied');
        $this->assertGreaterThanOrEqual(3, $rows->where('result', AutomationActionLog::SKIPPED)->count());

        // And the hand-off to a person is in the history as exactly that.
        $handOff = $rows->where('action_type', 'hand_off')->firstWhere('result', AutomationActionLog::NEEDS_HUMAN);
        $this->assertNotNull($handOff);
        $this->assertStringContainsString('ردّ العميل', $handOff->after);

        // The score change from the reply rule shows before and after.
        $score = $rows->where('action_type', 'adjust_score')->last();
        $this->assertSame((int) $score->before + 10, (int) $score->after);
    }

    public function test_a_rule_that_adds_to_the_score_actually_moves_it(): void
    {
        $lead = $this->arrive();

        $this->rule(['name' => 'فتح البروشور', 'trigger' => 'lead.file_opened', 'actions' => [['type' => 'adjust_score', 'by' => 15]]]);
        $this->asTenant($this->t['tenant'], fn () => LeadSignal::dispatch($lead->fresh(), 'lead.file_opened'));

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            $lead = $lead->fresh();
            $this->assertSame(15, (int) $lead->score_adjustment);

            // What the facts alone are worth now, and the rule's fifteen on top.
            $facts = (clone $lead)->forceFill(['score_adjustment' => 0]);
            $base = app(LeadScoringService::class)->evaluate($facts)['score'];
            $this->assertSame(min(100, $base + 15), (int) $lead->score);

            // And it survives the lead being scored again from its facts.
            app(LeadScoringService::class)->apply($lead);
            $this->assertSame(min(100, $base + 15), (int) $lead->score);
        });
    }

    // ------------------------------------------------------------ modes

    public function test_a_monitored_rule_writes_what_it_would_do_and_does_nothing(): void
    {
        $this->rule(['name' => 'مراقبة', 'trigger' => 'lead.email_opened', 'mode' => 'monitor', 'actions' => [['type' => 'send_whatsapp', 'purpose' => 'reminder']]]);
        $lead = $this->arrive();
        $sent = $this->asTenant($this->t['tenant'], fn () => LeadMessage::where('lead_id', $lead->id)->count());

        $this->asTenant($this->t['tenant'], fn () => LeadSignal::dispatch($lead->fresh(), 'lead.email_opened'));

        $this->assertSame($sent, $this->asTenant($this->t['tenant'], fn () => LeadMessage::where('lead_id', $lead->id)->count()));
        $row = $this->history($lead)->firstWhere('rule_name', 'مراقبة');
        $this->assertSame(AutomationActionLog::SKIPPED, $row->result);
        $this->assertSame('monitor', $row->mode);
        $this->assertStringContainsString('كان سيُنفّذ', $row->description);
    }

    public function test_a_recommending_rule_asks_the_owner_and_does_nothing(): void
    {
        $this->rule(['name' => 'توصية', 'trigger' => 'lead.email_clicked', 'mode' => 'recommend', 'actions' => [['type' => 'change_stage', 'status' => 'qualified']]]);
        $lead = $this->arrive(['assigned_to' => $this->t['admin']->id]);

        $this->asTenant($this->t['tenant'], fn () => LeadSignal::dispatch($lead->fresh(), 'lead.email_clicked'));

        $this->assertSame('new', $this->asTenant($this->t['tenant'], fn () => $lead->fresh()->status->value));
        $this->assertSame(AutomationActionLog::NEEDS_HUMAN, $this->history($lead)->firstWhere('rule_name', 'توصية')->result);
        $this->assertTrue($this->t['admin']->fresh()->notifications()->get()->contains(fn ($n) => str_contains(json_encode($n->data, JSON_UNESCAPED_UNICODE), 'توصية من «توصية»')));
    }

    // ------------------------------------------------------------ triggers

    public function test_quiet_for_fifteen_minutes_fires_once_and_the_template_sends_the_reminder(): void
    {
        $this->asTenant($this->t['tenant'], fn () => AutomationRule::where('template_key', 'lead_journey')->update(['is_enabled' => false]));
        $this->actingAs($this->t['admin'])->postJson('/api/v1/automation/templates/no_reply_follow_up')->assertCreated();
        $this->asTenant($this->t['tenant'], fn () => AutomationRule::where('template_key', 'no_reply_follow_up')->update(['is_enabled' => true]));

        $lead = $this->arrive();
        $this->asTenant($this->t['tenant'], fn () => LeadMessage::create([
            'lead_id' => $lead->id, 'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Whatsapp, 'provider' => 'whatsapp_cloud',
            'provider_message_id' => 'wamid.out.x', 'message_type' => 'text', 'body' => 'مرحبًا', 'status' => 'sent',
        ]));
        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['last_contact_at' => now()])->saveQuietly());

        $this->travel(16)->minutes();
        $fired = $this->asTenant($this->t['tenant'], fn () => app(NoReplySweeper::class)->sweep($this->t['tenant']));
        $again = $this->asTenant($this->t['tenant'], fn () => app(NoReplySweeper::class)->sweep($this->t['tenant']));

        $this->assertSame(1, $fired);
        $this->assertSame(0, $again);

        $reminder = $this->history($lead)->where('trigger', 'lead.no_reply')->firstWhere('action_type', 'send_whatsapp');
        $this->assertSame(AutomationActionLog::COMPLETED, $reminder->result);
    }

    public function test_a_score_of_eighty_makes_a_priority(): void
    {
        $this->actingAs($this->t['admin'])->postJson('/api/v1/automation/templates/priority_lead')->assertCreated();
        $this->asTenant($this->t['tenant'], fn () => AutomationRule::where('template_key', 'priority_lead')->update(['is_enabled' => true]));
        $lead = $this->arrive(['assigned_to' => $this->t['admin']->id]);

        $this->asTenant($this->t['tenant'], fn () => $lead->fresh()->forceFill(['score' => 84])->save());

        $this->assertTrue($this->asTenant($this->t['tenant'], fn () => (bool) $lead->fresh()->is_priority));
    }

    public function test_a_new_sheet_row_is_its_own_trigger_and_only_for_sheets(): void
    {
        $this->rule(['name' => 'صف جديد', 'trigger' => 'lead.sheet_row', 'actions' => [['type' => 'add_tag', 'tag' => 'من الشيت']]]);

        $fromSheet = $this->arrive();
        $fromMeta = $this->arrive(['phone' => '+966559998888', 'email' => null], IntegrationProvider::Meta);

        $this->assertNotNull($this->history($fromSheet)->firstWhere('rule_name', 'صف جديد'));
        $this->assertNull($this->history($fromMeta)->firstWhere('rule_name', 'صف جديد'));
    }

    public function test_a_condition_can_read_any_answer_the_form_carried(): void
    {
        $this->rule(['name' => 'فلل فقط', 'trigger' => 'lead.created', 'conditions' => [['field' => 'custom.unit_type', 'operator' => 'equals', 'value' => 'فيلا']], 'actions' => [['type' => 'add_tag', 'tag' => 'فلل']]]);
        $this->asTenant($this->t['tenant'], fn () => CustomField::create(['key' => 'unit_type', 'label' => 'نوع الوحدة', 'type' => 'text', 'is_active' => true]));

        $villa = $this->arrive(['custom' => ['unit_type' => 'فيلا']]);
        $flat = $this->arrive(['phone' => '+966557776666', 'email' => null, 'custom' => ['unit_type' => 'شقة']]);

        $this->assertSame(AutomationActionLog::COMPLETED, $this->history($villa)->firstWhere('rule_name', 'فلل فقط')?->result);
        $this->assertNull($this->history($flat)->firstWhere('rule_name', 'فلل فقط'));
        $this->assertSame('شقة', $this->asTenant($this->t['tenant'], fn () => CustomFieldValue::where('lead_id', $flat->id)->value('value')));
    }

    // ------------------------------------------------------------ the screen

    public function test_the_summary_compares_with_the_period_before_and_draws_the_funnel(): void
    {
        $this->arrive();
        $this->arrive(['phone' => '+966551112222', 'email' => null]);

        $data = $this->actingAs($this->t['admin'])->getJson('/api/v1/automation/summary?days=7')->assertOk()->json('data');

        $this->assertSame(2, $data['cards']['auto_contacted']['value']);
        $this->assertSame(0, $data['cards']['auto_contacted']['previous']);
        $this->assertSame(2, $data['cards']['auto_contacted']['change']);
        // The second lead has no email: that step is skipped, not failed.
        $this->assertSame(100, $data['success_rate']);
        $this->assertSame(['received', 'valid', 'auto_contacted', 'engaged', 'qualified', 'priority', 'sales'], array_column($data['funnel'], 'key'));
        $this->assertSame(2, $data['funnel'][0]['count']);
        $this->assertCount(14, $data['human_vs_automated']);
    }

    public function test_the_history_can_be_read_by_result(): void
    {
        $this->arrive();

        $waiting = $this->actingAs($this->t['admin'])->getJson('/api/v1/automation/history?result=waiting')->assertOk()->json('data.entries');

        $this->assertNotEmpty($waiting);
        $this->assertTrue(collect($waiting)->every(fn ($e) => $e['result'] === 'waiting'));
        $this->assertSame('أحمد السبيعي', $waiting[0]['lead']['name']);
    }

    public function test_the_rules_table_says_how_each_rule_last_ran(): void
    {
        $this->arrive();

        $rules = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/automation/rules')->assertOk()->json('data.rules'));
        $journey = $rules->firstWhere('template_key', 'lead_journey');

        $this->assertSame('applied', $journey['last_run']['outcome']);
        $this->assertSame('auto', $journey['mode']);
    }

    // ------------------------------------------------------------ scoring per project

    public function test_a_project_can_weigh_a_reply_and_set_its_own_lines(): void
    {
        $lead = $this->arrive();
        $scoring = app(LeadScoringService::class);

        $replied = fn () => $this->asTenant($this->t['tenant'], function () use ($lead, $scoring) {
            $copy = $lead->fresh()->forceFill(['first_response_at' => now(), 'score_adjustment' => 0]);

            return $scoring->evaluate($copy);
        });

        $before = $replied();

        $this->actingAs($this->t['admin'])->patchJson("/api/v1/projects/{$this->project->uuid}/scoring", ['whatsapp_reply' => 30, 'hot_from' => 50])
            ->assertOk()
            ->assertJsonPath('data.scoring.whatsapp_reply.value', 30)
            ->assertJsonPath('data.scoring.whatsapp_reply.custom', true)
            ->assertJsonPath('data.scoring.email_open.custom', false);

        $after = $replied();

        // The reply is now worth 30 instead of the workspace's 10.
        $this->assertSame(min(100, $before['score'] + 20), $after['score']);

        // And this project calls 50 hot.
        $this->asTenant($this->t['tenant'], function () use ($lead, $scoring) {
            $copy = $lead->fresh()->forceFill(['score_adjustment' => 0]);
            $copy->forceFill(['score_adjustment' => 55 - $scoring->evaluate($copy)['score']]);
            $this->assertSame('hot', $scoring->evaluate($copy)['temperature']->value);
        });
    }

    public function test_another_workspace_cannot_tune_this_project(): void
    {
        $other = $this->provisionTenant('Other Co');

        $this->actingAs($other['admin'])->patchJson("/api/v1/projects/{$this->project->uuid}/scoring", ['whatsapp_reply' => 50])->assertNotFound();
    }

    public function test_routines_say_how_often_they_run_and_when_they_last_did(): void
    {
        RoutineClock::stamp('sla_guardian');

        $routines = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/automation/routines')->assertOk()->json('data.routines'))->keyBy('key');

        $this->assertSame('ok', $routines['sla_guardian']['status']);
        $this->assertNotNull($routines['sla_guardian']['last_run_at']);
        $this->assertSame('not_run', $routines['no_reply_review']['status']);
        $this->assertSame('agent', $routines['lead_quality_review']['kind']);
    }
}
