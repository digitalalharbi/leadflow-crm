<?php

namespace Tests\Feature;

use App\DataTransferObjects\IncomingLead;
use App\Domain\Automation\FollowUpPlanner;
use App\Domain\Leads\InterestEngine;
use App\Enums\ActivityType;
use App\Enums\IntegrationProvider;
use App\Events\LeadSignal;
use App\Jobs\RunScheduledAction;
use App\Models\FileShare;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LostReason;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\ProjectQuestion;
use App\Models\Reservation;
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
 * When a customer shows they are coming toward us, the general nurture stops and a journey written for
 * that moment begins: the owner first, the customer a different message each time (never the same one
 * twice), a visit offered, a last word — and it ends the instant a visit is booked, a unit is held, the
 * deal is won or lost, the customer unsubscribes, or a person takes the conversation.
 */
class InterestedJourneyTest extends TestCase
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
        // The platform door is the product's own SMTP provider, configured: the one way email now leaves.
        config(['leadflow_mail.provider' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.mailers.smtp.username' => 'user']);

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
        // Each purpose is its own approved Meta template, as it is in a real workspace.
        foreach (['welcome', 'reminder', 'qualification', 'nurture', 'interested', 'interested_follow_up', 'visit_invite', 'final'] as $purpose) {
            $this->asTenant($this->t['tenant'], fn () => MessageTemplate::where('channel', 'whatsapp')->where('purpose', $purpose)
                ->update(['meta_template_name' => "{$purpose}_v1", 'meta_language' => 'ar', 'meta_parameters' => []]));
        }
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

    private function becomeInterested(Lead $lead, string $level = 'interested'): Lead
    {
        return $this->asTenant($this->t['tenant'], function () use ($lead) {
            $lead = $lead->fresh();
            $lead->forceFill(['interest_override' => 'interested'])->saveQuietly();
            app(InterestEngine::class)->refresh($lead->fresh(), 'test');

            return $lead->fresh();
        });
    }

    /** @return list<string> */
    private function purposes(Lead $lead): array
    {
        return $this->pending($this->fresh($lead))->map(fn ($s) => (string) ($s->action['purpose'] ?? $s->action['type']))->values()->all();
    }

    private function customerSteps(array $plan): array
    {
        return array_values(array_filter($plan['steps'], fn ($s) => in_array($s['channel'], ['whatsapp', 'email'], true) && $s['action'] !== null && ($s['action']['type'] ?? '') !== 'share_file'));
    }

    public function test_an_interested_lead_leaves_the_general_nurture_for_the_interested_journey(): void
    {
        $this->connectWhatsapp();
        $lead = $this->arrive();
        $this->assertContains('nurture', $this->purposes($lead), 'it started in the general nurture');

        $lead = $this->becomeInterested($lead);
        $plan = $this->plan($lead);

        $this->assertSame('interested', $plan['mode']);
        $this->assertSame(['now', '15m', '4h', '24h', '3d', '7d'], array_column($plan['steps'], 'key'));
        $this->assertSame('interested', $this->step($plan, 'now')['action']['purpose']);
        $this->assertSame('team', $this->step($plan, '15m')['channel'], 'the owner is reminded first');
        // The brochure already went at arrival, so it is not planned again.
        $this->assertNull($this->step($plan, '4h')['action']);
        $this->assertSame('already_sent', $this->step($plan, '4h')['why']);
        $this->assertSame('interested_follow_up', $this->step($plan, '24h')['action']['purpose']);
        $this->assertSame('visit_invite', $this->step($plan, '3d')['action']['purpose']);
        $this->assertSame('final', $this->step($plan, '7d')['action']['purpose']);

        $waiting = $this->pending($this->fresh($lead));
        $this->assertCount(0, $waiting->filter(fn ($s) => empty($s->action['plan'])), 'nothing of the old journey is left waiting');
        $this->assertCount(0, $waiting->filter(fn ($s) => in_array($s->action['purpose'] ?? null, ['nurture', 'reminder'], true) || ($s->action['type'] ?? '') === 'hand_off'), 'and no general nurture');
        $this->assertTrue((bool) $this->fresh($lead)->is_priority, 'it is first in Work Now');
    }

    public function test_the_brochure_is_part_of_it_only_when_it_has_not_been_sent(): void
    {
        $this->connectWhatsapp();
        $lead = $this->arrive();
        $this->asTenant($this->t['tenant'], fn () => FileShare::withoutGlobalScopes()->where('lead_id', $lead->id)->delete());

        $lead = $this->becomeInterested($lead);

        $this->assertSame('share_file', $this->step($this->plan($lead), '4h')['action']['type']);
    }

    public function test_the_customer_is_never_sent_the_same_message_twice(): void
    {
        $this->connectWhatsapp();
        $lead = $this->becomeInterested($this->arrive());
        $purposes = array_map(fn ($s) => $s['action']['purpose'], $this->customerSteps($this->plan($lead)));

        $this->assertCount(4, $purposes);
        $this->assertSame($purposes, array_values(array_unique($purposes)), 'every message is a different one');
        $this->assertSame([], array_values(array_intersect($purposes, ['welcome', 'reminder', 'nurture'])), 'and none is one the lead has already had');
    }

    public function test_the_journey_runs_in_order_and_each_message_is_personal_and_whole(): void
    {
        $this->connectWhatsapp();
        $lead = $this->becomeInterested($this->arrive());

        foreach ($this->pending($this->fresh($lead)) as $row) {
            Carbon::setTestNow($row->run_at->copy()->addMinute());
            $this->runStep($row->fresh());
        }
        Carbon::setTestNow();

        $sent = $this->asTenant($this->t['tenant'], fn () => LeadMessage::where('lead_id', $lead->id)->where('direction', 'outbound')->orderBy('id')->get());
        $bodies = $sent->pluck('body')->filter()->values();

        $this->assertGreaterThanOrEqual(4, $bodies->count());
        $this->assertCount($bodies->count(), $bodies->unique(), 'no two messages are the same');
        $this->assertSame(0, $bodies->filter(fn ($b) => str_contains($b, '{{'))->count(), 'every placeholder was filled');
        $this->assertTrue($bodies->contains(fn ($b) => str_contains($b, 'النرجس')), 'it speaks of the lead\'s own project');
        $this->assertTrue($bodies->contains(fn ($b) => str_contains($b, 'سلمان')), 'and by name');
    }

    public function test_it_stops_when_a_visit_is_booked_a_unit_is_held_or_the_deal_is_decided(): void
    {
        $this->connectWhatsapp();

        $visit = $this->becomeInterested($this->arrive(['phone' => '+966551110001', 'email' => 'a@example.com']));
        $this->asTenant($this->t['tenant'], fn () => app(ActivityLogger::class)->log($visit, ActivityType::AppointmentCreated, $this->t['admin'], 'زيارة'));
        $this->assertSame('stopped', $this->plan($visit)['status']);

        $held = $this->becomeInterested($this->arrive(['phone' => '+966551110002', 'email' => 'b@example.com']));
        $this->asTenant($this->t['tenant'], function () use ($held) {
            Reservation::create(['lead_id' => $held->id, 'project_id' => $this->project->id, 'status' => 'pending', 'reserved_at' => now(), 'expires_at' => now()->addDays(3)]);
            app(ActivityLogger::class)->log($held, ActivityType::ReservationCreated, $this->t['admin'], 'حجز');
        });
        $plan = $this->plan($held);
        $this->assertSame('stopped', $plan['status']);
        $this->assertSame('reservation', $plan['reason']);
        $this->assertCount(0, $this->pending($this->fresh($held))->filter(fn ($s) => str_starts_with($s->action['type'], 'send_')), 'nothing more is sent to somebody who reserved');

        $won = $this->becomeInterested($this->arrive(['phone' => '+966551110003', 'email' => 'c@example.com']));
        $this->asTenant($this->t['tenant'], fn () => $won->fresh()->forceFill(['status' => 'won'])->save());
        $this->assertSame('stopped', $this->plan($won)['status']);

        $lost = $this->becomeInterested($this->arrive(['phone' => '+966551110004', 'email' => 'd@example.com']));
        $this->asTenant($this->t['tenant'], function () use ($lost) {
            $reason = LostReason::firstOrCreate(['key' => 'price'], ['label' => 'السعر', 'is_active' => true]);
            $lost->fresh()->forceFill(['status' => 'lost', 'lost_reason_id' => $reason->id])->save();
        });
        $this->assertSame('stopped', $this->plan($lost)['status']);
    }

    public function test_a_customer_who_unsubscribes_from_everything_ends_it(): void
    {
        $this->connectWhatsapp();
        $lead = $this->becomeInterested($this->arrive());

        $this->asTenant($this->t['tenant'], fn () => $lead->fresh()->forceFill(['whatsapp_opt_out_at' => now(), 'email_opt_out_at' => now()])->save());

        $plan = $this->plan($lead);
        $this->assertSame('stopped', $plan['status']);
        $this->assertSame('unsubscribed', $plan['reason']);
    }

    public function test_a_person_taking_the_conversation_leaves_only_the_reps_steps(): void
    {
        $this->connectWhatsapp();
        $lead = $this->becomeInterested($this->arrive());

        $this->reply($lead);

        $plan = $this->plan($lead);
        $this->assertSame('interested', $plan['mode'], 'still the interested journey, now the rep\'s');
        $this->assertSame([], $this->customerSteps($plan), 'nothing automatic is written to the customer');
        $left = $this->pending($this->fresh($lead));
        $this->assertCount(0, $left->filter(fn ($s) => str_starts_with($s->action['type'], 'send_') || $s->action['type'] === 'share_file'));
    }

    public function test_quiet_hours_hold_the_customer_messages_but_not_the_reminder_to_the_rep(): void
    {
        $this->connectWhatsapp();
        $this->asTenant($this->t['tenant'], fn () => TenantSetting::first()->forceFill(['quiet_hours_enabled' => true, 'quiet_hours_start' => '00:00', 'quiet_hours_end' => '23:59', 'timezone' => 'UTC'])->save());
        $lead = $this->becomeInterested($this->arrive());

        $plan = $this->plan($lead);
        $this->assertStringContainsString('quiet_hours', $this->step($plan, 'now')['why']);
        $this->assertStringNotContainsString('quiet_hours', $this->step($plan, '15m')['why'], 'the rep is not asked to wait for the morning');
    }

    public function test_a_lead_that_cools_goes_back_to_the_general_nurture(): void
    {
        $this->connectWhatsapp();
        $lead = $this->becomeInterested($this->arrive());
        $this->assertSame('interested', $this->plan($lead)['mode']);

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            $lead->fresh()->forceFill(['interest_override' => 'not_interested'])->saveQuietly();
            app(InterestEngine::class)->refresh($lead->fresh(), 'test');
        });

        $this->assertSame('automated', $this->plan($lead)['mode']);
    }

    public function test_getting_hotter_does_not_start_the_journey_again(): void
    {
        $this->connectWhatsapp();
        $lead = $this->becomeInterested($this->arrive());
        $before = $this->pending($this->fresh($lead))->pluck('id')->all();

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            $fresh = $lead->fresh();
            $fresh->forceFill(['interest_level' => 'hot'])->saveQuietly();
            app(FollowUpPlanner::class)->onEvent(new LeadSignal($fresh->fresh(), 'lead.interest_changed', ['from' => 'interested', 'to' => 'hot']));
        });

        $this->assertSame($before, $this->pending($this->fresh($lead))->pluck('id')->all(), 'the same steps, not a second set');
    }

    public function test_a_plan_a_person_paused_is_not_restarted_by_interest(): void
    {
        $this->connectWhatsapp();
        $lead = $this->arrive();
        $this->asTenant($this->t['tenant'], fn () => app(FollowUpPlanner::class)->pause($lead->fresh(), 'مدير'));

        $this->becomeInterested($lead);

        $this->assertSame('paused', $this->plan($lead)['status']);
        $this->assertCount(0, $this->pending($this->fresh($lead)));
    }

    public function test_the_workspaces_own_message_for_a_purpose_wins_over_the_default(): void
    {
        $this->connectWhatsapp();
        $this->asTenant($this->t['tenant'], fn () => MessageTemplate::where('channel', 'whatsapp')->where('purpose', 'interested')->update(['body' => 'نص الشركة الخاص {{name}} {{project}}']));
        $lead = $this->becomeInterested($this->arrive());

        $now = $this->pending($this->fresh($lead))->first(fn ($s) => ($s->action['purpose'] ?? null) === 'interested');
        // Past the cooldown the guardrails keep between two automated messages.
        Carbon::setTestNow(now()->addHour());
        $this->runStep($now);
        Carbon::setTestNow();

        $bodies = $this->asTenant($this->t['tenant'], fn () => LeadMessage::where('lead_id', $lead->id)->where('direction', 'outbound')->pluck('body'));
        $this->assertTrue($bodies->contains(fn ($b) => str_contains((string) $b, 'نص الشركة الخاص')), 'the workspace\'s own words went out');
    }
}
