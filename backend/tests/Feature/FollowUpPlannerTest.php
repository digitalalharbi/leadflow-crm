<?php

namespace Tests\Feature;

use App\DataTransferObjects\IncomingLead;
use App\Domain\Automation\FollowUpPlanner;
use App\Enums\ActivityType;
use App\Enums\IntegrationProvider;
use App\Jobs\RunScheduledAction;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LostReason;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\ProjectQuestion;
use App\Models\ScheduledAction;
use App\Models\TenantSetting;
use App\Services\Leads\ActivityLogger;
use App\Services\Leads\LeadIngestionService;
use App\Services\Whatsapp\InboundWhatsappProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Every lead has the next week laid out — now, 15 minutes, 4 hours, 24 hours, 3 days, 7 days —
 * and the plan looks again before each step: keep going, hand the next move to a person, or stop.
 *
 * Each test is one situation from a sales floor: nobody answers, the customer answers, a rep
 * calls, it is the middle of the night, WhatsApp will not go, a visit is booked, the deal is won
 * or lost, the customer unsubscribes, and a person pauses, moves or ends the plan.
 */
class FollowUpPlannerTest extends TestCase
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
        config(['mail.default' => 'smtp']);

        // Steps are inspected, then run by hand: a day's wait is not something a test sleeps through.
        Queue::fake([RunScheduledAction::class]);

        $this->t = $this->provisionTenant('Planner Co');
        $this->project = $this->asTenant($this->t['tenant'], function () {
            $project = Project::create(['name' => 'النرجس']);
            ProjectFile::create(['project_id' => $project->id, 'name' => 'بروشور', 'kind' => 'brochure', 'path' => 'project-files/'.uniqid().'.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1024, 'is_active' => true]);
            ProjectQuestion::create(['project_id' => $project->id, 'key' => 'budget', 'label' => 'ما ميزانيتك؟', 'type' => 'text', 'is_active' => true, 'sort_order' => 1]);

            return $project;
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function connectWhatsapp(): void
    {
        $this->whatsapp = $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'whatsapp', 'name' => 'WhatsApp Business', 'status' => Integration::CONNECTED,
            'config' => ['phone_number_id' => '1555000111'],
            'credentials' => ['access_token' => 'token', 'verify_token' => 'v', 'app_secret' => 's'], 'is_active' => true,
        ]));
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]], 200)]);
        $this->asTenant($this->t['tenant'], fn () => MessageTemplate::where('channel', 'whatsapp')->whereIn('purpose', ['welcome', 'reminder', 'qualification', 'nurture'])
            ->update(['meta_template_name' => 'welcome_v1', 'meta_language' => 'ar', 'meta_parameters' => []]));
    }

    private function arrive(array $data = []): Lead
    {
        $result = app(LeadIngestionService::class)->ingest($this->t['tenant'], IncomingLead::fromArray(IntegrationProvider::GoogleSheets, $data + [
            'full_name' => 'سلمان القحطاني', 'phone' => '+966551234567', 'email' => 'salman@example.com',
            'project_id' => $this->project->id, 'platform' => 'meta',
        ]));

        return $this->asTenant($this->t['tenant'], fn () => $result->lead->fresh());
    }

    private function fresh(Lead $lead): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => $lead->fresh());
    }

    private function plan(Lead $lead): array
    {
        return $this->asTenant($this->t['tenant'], fn () => app(FollowUpPlanner::class)->view($this->fresh($lead)));
    }

    private function step(array $plan, string $key): array
    {
        return collect($plan['steps'])->firstWhere('key', $key);
    }

    private function pending(Lead $lead)
    {
        return ScheduledAction::withoutGlobalScopes()->where('lead_id', $lead->id)->where('status', ScheduledAction::PENDING)->orderBy('run_at')->get();
    }

    private function runStep(ScheduledAction $row): void
    {
        $this->asTenant($this->t['tenant'], fn () => app()->call([new RunScheduledAction($row->id), 'handle']));
    }

    private function reply(Lead $lead): void
    {
        $this->asTenant($this->t['tenant'], fn () => app(InboundWhatsappProcessor::class)->process($this->whatsapp, [
            'entry' => [['changes' => [['value' => [
                'metadata' => ['phone_number_id' => '1555000111'], 'contacts' => [['wa_id' => '966551234567']],
                'messages' => [['from' => '966551234567', 'id' => 'wamid.in.'.uniqid(), 'type' => 'text', 'text' => ['body' => 'نعم مهتم'], 'timestamp' => (string) time()]],
            ]]]]],
        ]));
    }

    private function api(string $method, Lead $lead, string $path = '', array $body = [])
    {
        return $this->actingAs($this->t['admin'])->json($method, "/api/v1/leads/{$lead->uuid}/follow-up-plan{$path}", $body);
    }

    // ------------------------------------------------------------ the plan itself

    public function test_a_new_lead_gets_the_week_laid_out_on_the_channels_it_can_be_reached_by(): void
    {
        $this->connectWhatsapp();
        $lead = $this->arrive();

        $plan = $this->plan($lead);

        $this->assertSame('automated', $plan['mode']);
        $this->assertSame('active', $plan['status']);
        $this->assertSame(['now', '15m', '4h', '24h', '3d', '7d'], array_column($plan['steps'], 'key'));
        $this->assertSame('continue', $plan['decision']);
        $this->assertSame('no_reply', $plan['reason']);

        // Every step says what, on which channel, and why — and the next one is named.
        $this->assertSame('whatsapp', $this->step($plan, '15m')['channel']);
        $this->assertSame('email', $this->step($plan, '4h')['channel'], 'a customer who stays silent is given the other door');
        $this->assertSame('call', $this->step($plan, '3d')['channel']);
        $this->assertSame('team', $this->step($plan, '7d')['channel']);
        $this->assertNotNull($plan['next']);
        $this->assertGreaterThan(0, $plan['progress']['total']);
        $this->assertTrue($plan['can']['pause'] && $plan['can']['stop'] && $plan['can']['reschedule']);
    }

    public function test_no_reply_climbs_the_ladder_to_a_call_and_then_to_a_person(): void
    {
        $this->connectWhatsapp();
        $lead = $this->arrive();

        // Days pass, nobody answers: every waiting step comes due in turn.
        foreach ($this->pending($lead) as $row) {
            Carbon::setTestNow($row->run_at->copy()->addMinute());
            $this->runStep($row->fresh());
        }
        Carbon::setTestNow();

        $lead = $this->fresh($lead);
        $this->assertSame(Lead::AUTOMATION_HANDED_OFF, $lead->automation_state);
        $this->assertSame('no_reply', $lead->needs_human_reason);

        $plan = $this->plan($lead);
        $this->assertSame('escalate', $plan['decision']);
        $this->assertSame('done', $this->step($plan, '3d')['state']);
        $this->assertSame('done', $this->step($plan, '7d')['state']);
    }

    // ------------------------------------------------------------ the customer answers

    public function test_a_reply_turns_the_plan_over_to_the_rep_and_stops_messaging_the_customer(): void
    {
        $this->connectWhatsapp();
        $lead = $this->arrive();

        $this->reply($lead);

        $plan = $this->plan($lead);
        $this->assertSame('human', $plan['mode']);
        $this->assertSame('escalate', $plan['decision']);
        $this->assertSame('replied', $plan['reason']);

        $left = $this->pending($this->fresh($lead));
        $this->assertCount(0, $left->filter(fn ($s) => str_starts_with($s->action['type'], 'send_') || $s->action['type'] === 'hand_off'), 'no more messages to someone who answered');
        $this->assertTrue($left->every(fn ($s) => $s->action['type'] === 'create_call_task' || $s->action['type'] === 'notify'), 'what remains is the rep\'s');
    }

    public function test_a_rep_calling_falls_away_every_waiting_step_and_the_plan_is_the_reps_now(): void
    {
        $this->connectWhatsapp();
        $lead = $this->arrive();

        $this->asTenant($this->t['tenant'], fn () => app(ActivityLogger::class)->log($lead, ActivityType::Called, $this->t['admin'], 'اتصال'));

        $this->assertCount(0, $this->pending($this->fresh($lead)));
        $plan = $this->plan($lead);
        $this->assertSame('escalate', $plan['decision']);
        $this->assertSame('human_takeover', $plan['reason']);
        $this->assertSame(Lead::AUTOMATION_HANDED_OFF, $this->fresh($lead)->automation_state);
    }

    // ------------------------------------------------------------ when it is allowed to speak

    public function test_quiet_hours_move_every_message_to_the_end_of_the_night_but_not_the_calls(): void
    {
        $this->asTenant($this->t['tenant'], fn () => TenantSetting::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->firstOrFail()
            ->update(['quiet_hours_enabled' => true, 'quiet_hours_start' => '21:00', 'quiet_hours_end' => '08:00', 'timezone' => 'UTC']));
        Carbon::setTestNow(Carbon::parse('2026-10-23 23:30:00', 'UTC'));
        $this->connectWhatsapp();

        $lead = $this->arrive();
        $plan = $this->plan($lead);

        $fifteen = $this->step($plan, '15m');
        $this->assertSame('2026-10-24 08:00:00', Carbon::parse($fifteen['at'])->utc()->format('Y-m-d H:i:s'), 'the 23:45 reminder waits for morning');
        $this->assertStringContainsString('quiet_hours', $fifteen['why']);
        $this->assertStringNotContainsString('quiet_hours', $this->step($plan, '3d')['why'], 'a call is the rep\'s to place, not held by the customer\'s night');
    }

    public function test_a_project_that_is_paused_is_not_messaged_by_the_plan(): void
    {
        $this->connectWhatsapp();
        $lead = $this->arrive();

        $this->asTenant($this->t['tenant'], fn () => $this->project->forceFill(['automation_settings' => ['active' => false]])->save());
        $plan = $this->asTenant($this->t['tenant'], fn () => app(FollowUpPlanner::class)->plan($this->fresh($lead)));

        $this->assertSame('project_paused', $this->step($plan, '15m')['why']);
        $this->assertSame('skipped', $this->step($plan, '15m')['state']);
        $this->assertNotSame('skipped', $this->step($plan, '3d')['state'], 'a call is a person\'s, and a paused project does not stop people');
    }

    // ------------------------------------------------------------ when a channel fails

    public function test_a_whatsapp_that_cannot_go_is_sent_by_email_and_the_plan_says_so(): void
    {
        // Nothing connected for WhatsApp: the reminder cannot go that way.
        $this->asTenant($this->t['tenant'], function () {
            MessageTemplate::create(['name' => 'تذكير واتساب', 'body' => 'مرحبًا {{name}}', 'channel' => 'whatsapp', 'purpose' => 'reminder', 'is_active' => true]);
            MessageTemplate::create(['name' => 'تذكير بريد', 'subject' => 'تذكير', 'body' => 'مرحبًا {{name}}', 'channel' => 'email', 'purpose' => 'reminder', 'is_active' => true]);
        });
        $lead = $this->arrive();

        $reminder = ScheduledAction::withoutGlobalScopes()->find($this->step($this->plan($lead), '15m')['scheduled_action_id']);
        $this->assertNotNull($reminder, 'the plan scheduled a WhatsApp reminder');
        $this->assertSame('send_whatsapp', $reminder->action['type']);
        $this->runStep($reminder);

        $this->assertSame(ScheduledAction::DONE, $reminder->fresh()->status);
        $step = $this->step($this->plan($lead), '15m');
        $this->assertTrue($step['fallback']);
        $this->assertSame('email', $step['channel']);
    }

    public function test_a_lead_with_only_an_email_is_planned_on_email(): void
    {
        $plan = $this->plan($this->arrive(['phone' => null]));

        foreach (['now', '15m', '24h'] as $key) {
            $this->assertSame('email', $this->step($plan, $key)['channel']);
        }
    }

    // ------------------------------------------------------------ when it is decided

    public function test_a_booked_visit_ends_the_follow_up(): void
    {
        $this->connectWhatsapp();
        $lead = $this->arrive();

        $this->actingAs($this->t['admin'])->postJson('/api/v1/appointments', [
            'lead_id' => $lead->uuid, 'scheduled_at' => now()->addDays(2)->toIso8601String(),
        ])->assertCreated();

        $plan = $this->plan($lead);
        $this->assertSame('stopped', $plan['status']);
        $this->assertSame('appointment', $plan['reason']);
        $this->assertCount(0, $this->pending($this->fresh($lead))->filter(fn ($s) => ! empty($s->action['plan'])));
    }

    public function test_a_won_lead_is_not_followed_up(): void
    {
        $this->connectWhatsapp();
        $lead = $this->arrive();

        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$lead->uuid}/status", ['status' => 'won'])->assertOk();

        $plan = $this->plan($lead);
        $this->assertSame('stopped', $plan['status']);
        $this->assertSame('won', $plan['reason']);
        $this->assertCount(0, $this->pending($this->fresh($lead)));
    }

    public function test_a_lost_lead_is_not_followed_up(): void
    {
        $this->connectWhatsapp();
        $lead = $this->arrive();

        $reason = $this->asTenant($this->t['tenant'], fn () => LostReason::create(['label' => 'السعر']));
        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$lead->uuid}/status", ['status' => 'lost', 'lost_reason_id' => $reason->uuid])->assertOk();

        $plan = $this->plan($lead);
        $this->assertSame('stopped', $plan['status']);
        $this->assertSame('lost', $plan['reason']);
        $this->assertCount(0, $this->pending($this->fresh($lead)));
    }

    public function test_a_customer_who_unsubscribes_everywhere_ends_the_plan_but_one_channel_only_replans(): void
    {
        $this->connectWhatsapp();
        $lead = $this->arrive();

        // WhatsApp only: the rest is laid out again on email.
        $this->asTenant($this->t['tenant'], fn () => $this->fresh($lead)->forceFill(['whatsapp_opt_out_at' => now()])->save());
        $plan = $this->plan($lead);
        $this->assertSame('active', $plan['status']);
        $this->assertNotSame('whatsapp', $this->step($plan, '24h')['channel']);

        // And then email: nobody left to write to.
        $this->asTenant($this->t['tenant'], fn () => $this->fresh($lead)->forceFill(['email_opt_out_at' => now()])->save());
        $plan = $this->plan($lead);
        $this->assertSame('stopped', $plan['status']);
        $this->assertSame('unsubscribed', $plan['reason']);
        $this->assertCount(0, $this->pending($this->fresh($lead))->filter(fn ($s) => str_starts_with($s->action['type'], 'send_')));
    }

    public function test_a_contact_that_cannot_be_reached_goes_to_a_person(): void
    {
        $lead = $this->arrive(['phone' => null]);
        $this->asTenant($this->t['tenant'], fn () => $this->fresh($lead)->forceFill(['email_bounced_at' => now()])->save());

        $plan = $this->plan($lead);

        $this->assertSame('escalate', $plan['decision']);
        $this->assertSame('invalid_contact', $plan['reason']);
    }

    // ------------------------------------------------------------ the three buttons

    public function test_pause_holds_everything_and_resume_lays_the_week_out_again(): void
    {
        $this->connectWhatsapp();
        $lead = $this->arrive();

        $paused = $this->api('POST', $lead, '/pause')->assertOk()->json('data.follow_up');
        $this->assertSame('paused', $paused['status']);
        $this->assertCount(0, $this->pending($this->fresh($lead)));
        $this->assertTrue($paused['can']['resume']);
        $this->assertFalse($paused['can']['pause']);

        // A reply while it is paused does not undo the pause.
        $this->reply($lead);
        $this->assertSame('paused', $this->plan($lead)['status']);

        $resumed = $this->api('POST', $lead, '/resume')->assertOk()->json('data.follow_up');
        $this->assertNotSame('paused', $resumed['status']);
        $this->assertGreaterThan(0, $this->pending($this->fresh($lead))->count());
    }

    public function test_reschedule_moves_every_waiting_step_keeping_the_gaps(): void
    {
        $this->connectWhatsapp();
        $lead = $this->arrive();
        $before = $this->pending($lead);
        $gaps = $before->map(fn ($s) => $s->run_at->timestamp - $before[0]->run_at->timestamp)->all();
        $to = now()->addDays(2)->startOfHour();

        $plan = $this->api('POST', $lead, '/reschedule', ['at' => $to->toIso8601String()])->assertOk()->json('data.follow_up');

        $after = $this->pending($this->fresh($lead));
        $this->assertCount($before->count(), $after);
        $this->assertSame($to->timestamp, $after[0]->run_at->timestamp);
        $this->assertSame($gaps, $after->map(fn ($s) => $s->run_at->timestamp - $after[0]->run_at->timestamp)->all());
        $this->assertSame($after->firstWhere('id', $plan['next']['scheduled_action_id'])->run_at->timestamp, Carbon::parse($plan['next']['at'])->timestamp, 'the plan shows the new time');
        $this->assertGreaterThanOrEqual($to->timestamp, Carbon::parse($plan['next']['at'])->timestamp);
        $this->assertSame('rescheduled', $plan['next']['why']);

        // The old timer going off at the old time does nothing: the step has moved.
        $first = $after[0];
        Carbon::setTestNow($before[0]->run_at->copy()->addMinute());
        $this->runStep($first);
        $this->assertSame(ScheduledAction::PENDING, $first->fresh()->status);
    }

    public function test_reschedule_into_the_past_is_refused_and_nothing_waiting_is_422(): void
    {
        $this->connectWhatsapp();
        $lead = $this->arrive();

        $this->api('POST', $lead, '/reschedule', ['at' => now()->subHour()->toIso8601String()])->assertStatus(422);

        $this->api('POST', $lead, '/stop')->assertOk();
        $this->api('POST', $lead, '/reschedule', ['at' => now()->addDay()->toIso8601String()])->assertStatus(422);
    }

    public function test_stop_ends_the_follow_up_and_a_later_reply_does_not_restart_it(): void
    {
        $this->connectWhatsapp();
        $lead = $this->arrive();

        $stopped = $this->api('POST', $lead, '/stop')->assertOk()->json('data.follow_up');
        $this->assertSame('stopped', $stopped['status']);
        $this->assertSame('stopped_by_user', $stopped['reason']);
        $this->assertCount(0, $this->pending($this->fresh($lead)));

        $this->reply($lead);
        $this->assertSame('stopped', $this->plan($lead)['status']);
        $this->assertCount(0, $this->pending($this->fresh($lead))->filter(fn ($s) => ! empty($s->action['plan'])));
    }

    public function test_the_lead_carries_its_plan_and_another_workspace_cannot_touch_it(): void
    {
        $this->connectWhatsapp();
        $lead = $this->arrive();

        $this->actingAs($this->t['admin'])->getJson("/api/v1/leads/{$lead->uuid}")->assertOk()
            ->assertJsonPath('data.follow_up.status', 'active')
            ->assertJsonCount(6, 'data.follow_up.steps');

        $other = $this->provisionTenant('Someone Else');
        $this->actingAs($other['admin'])->postJson("/api/v1/leads/{$lead->uuid}/follow-up-plan/stop")->assertNotFound();
        $this->assertSame('active', $this->plan($lead)['status']);
    }
}
