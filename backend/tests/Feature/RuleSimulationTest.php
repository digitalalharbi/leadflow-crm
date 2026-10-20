<?php

namespace Tests\Feature;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Jobs\RunScheduledAction;
use App\Models\AutomationActionLog;
use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadMessage;
use App\Models\Project;
use App\Models\ScheduledAction;
use App\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * "If this rule had run in the last 7 days" — and the promise that asking is
 * free: nothing sent, queued, logged or notified.
 */
class RuleSimulationTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->t = $this->provisionTenant('Sim Co');
    }

    private function rule(array $attributes = []): AutomationRule
    {
        return $this->asTenant($this->t['tenant'], fn () => AutomationRule::create(array_merge([
            'name' => 'قاعدة التجربة', 'trigger' => 'lead.created', 'conditions' => [], 'mode' => 'auto', 'is_enabled' => true, 'priority' => 100,
            'actions' => [['type' => 'send_whatsapp', 'purpose' => 'welcome'], ['type' => 'send_email', 'purpose' => 'welcome'], ['type' => 'change_stage', 'stage' => 'contacted'], ['type' => 'hand_off', 'reason' => 'needs_human']],
        ], $attributes)));
    }

    private function lead(array $attributes = []): Lead
    {
        $guarded = array_intersect_key($attributes, array_flip(['created_at', 'automation_state']));

        return $this->asTenant($this->t['tenant'], function () use ($attributes, $guarded) {
            $lead = Lead::create(array_merge([
                'full_name' => 'عميل '.random_int(100, 999), 'phone' => '0551234567', 'email' => 'x'.random_int(1, 99999).'@example.com',
                'phone_normalized' => '+9665512'.random_int(10000, 99999), 'status' => 'new',
            ], array_diff_key($attributes, $guarded)));
            if ($guarded) {
                $lead->forceFill($guarded)->saveQuietly();
            }

            return $lead;
        });
    }

    private function simulate(AutomationRule $rule): array
    {
        return $this->actingAs($this->t['admin'])->getJson("/api/v1/automation/rules/{$rule->uuid}/simulate")->assertOk()->json('data.simulation');
    }

    public function test_it_counts_what_the_rule_would_have_done_to_the_leads_of_the_last_week(): void
    {
        $rule = $this->rule();
        foreach (range(1, 4) as $i) {
            $this->lead(['created_at' => now()->subDays($i)]);
        }
        $this->lead(['created_at' => now()->subDays(20)]);

        $sim = $this->simulate($rule);

        $this->assertSame(7, $sim['days']);
        $this->assertSame(4, $sim['trigger_events'], 'the old lead is outside the window');
        $this->assertSame(4, $sim['leads_affected']);
        $this->assertSame(4, $sim['messages']['whatsapp']);
        $this->assertSame(4, $sim['messages']['email']);
        $this->assertSame(8, $sim['messages']['total']);
        $this->assertSame(4, $sim['stage_changes']['total']);
        $this->assertSame(['contacted' => 4], $sim['stage_changes']['to']);
        $this->assertSame(4, $sim['escalations']);
        $this->assertCount(4, array_unique(array_column($sim['examples'], 'id')) ?: [], 'examples are distinct leads');
    }

    public function test_the_rules_own_conditions_decide_who_is_affected(): void
    {
        $rule = $this->rule(['conditions' => [['field' => 'platform', 'operator' => 'equals', 'value' => 'tiktok']]]);
        $this->lead(['platform' => 'tiktok']);
        $this->lead(['platform' => 'tiktok']);
        $this->lead(['platform' => 'facebook']);

        $sim = $this->simulate($rule);

        $this->assertSame(3, $sim['trigger_events']);
        $this->assertSame(2, $sim['leads_affected']);
    }

    public function test_messages_the_guardrails_would_hold_are_counted_apart(): void
    {
        $paused = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'موقوف', 'automation_settings' => ['active' => false]]));
        $rule = $this->rule(['actions' => [['type' => 'send_whatsapp', 'purpose' => 'welcome']]]);
        $this->lead();
        $this->lead(['project_id' => $paused->id]);
        $this->lead(['automation_state' => Lead::AUTOMATION_HANDED_OFF]);

        $sim = $this->simulate($rule);

        $this->assertSame(3, $sim['leads_affected']);
        $this->assertSame(1, $sim['messages']['whatsapp']);
        $this->assertSame(['project_paused' => 1, 'human_takeover' => 1], $sim['messages']['held']);
    }

    public function test_each_trigger_looks_at_its_own_kind_of_event(): void
    {
        $this->lead(['created_at' => now()->subDays(2)]);
        $replied = $this->lead();
        $this->asTenant($this->t['tenant'], fn () => $replied->forceFill(['first_response_at' => now()->subDay()])->saveQuietly());

        $this->assertSame(1, $this->simulate($this->rule(['trigger' => 'lead.replied']))['trigger_events']);
        $this->assertSame(2, $this->simulate($this->rule(['trigger' => 'lead.created']))['trigger_events']);
    }

    public function test_asking_changes_nothing(): void
    {
        $rule = $this->rule();
        $lead = $this->lead();
        $before = [
            LeadMessage::withoutGlobalScopes()->count(), AutomationActionLog::withoutGlobalScopes()->count(),
            ScheduledAction::withoutGlobalScopes()->count(), DatabaseNotification::count(),
            $this->asTenant($this->t['tenant'], fn () => $lead->fresh()->status->value), $rule->fresh()->times_matched,
        ];

        $this->simulate($rule);
        $this->simulate($rule);

        $this->assertSame($before, [
            LeadMessage::withoutGlobalScopes()->count(), AutomationActionLog::withoutGlobalScopes()->count(),
            ScheduledAction::withoutGlobalScopes()->count(), DatabaseNotification::count(),
            $this->asTenant($this->t['tenant'], fn () => $lead->fresh()->status->value), $rule->fresh()->times_matched,
        ]);
        Queue::assertNotPushed(RunScheduledAction::class);
    }

    public function test_a_rep_sees_only_their_own_leads_and_other_workspaces_see_nothing(): void
    {
        $rule = $this->rule();
        $this->lead(['assigned_to' => $this->t['admin']->id]);
        $other = $this->provisionTenant('Other Co');
        $rep = $this->makeUser($this->t['tenant']);

        $this->assertSame(1, $this->simulate($rule)['leads_affected']);
        $this->assertSame(0, $this->actingAs($rep)->getJson("/api/v1/automation/rules/{$rule->uuid}/simulate")->assertOk()->json('data.simulation.leads_affected'), 'a rep is shown what concerns their own leads');
        $this->actingAs($other['admin'])->getJson("/api/v1/automation/rules/{$rule->uuid}/simulate")->assertNotFound();
    }

    // ------------------------------------------------------- history, not today

    private function activity(Lead $lead, string $type, array $meta, $at): void
    {
        $this->asTenant($this->t['tenant'], function () use ($lead, $type, $meta, $at) {
            $a = LeadActivity::create(['lead_id' => $lead->id, 'type' => $type, 'meta' => $meta]);
            $a->forceFill(['created_at' => $at])->saveQuietly();
        });
    }

    public function test_conditions_are_read_as_the_lead_was_when_the_trigger_fired(): void
    {
        $rule = $this->rule(['conditions' => [['field' => 'status', 'operator' => 'equals', 'value' => 'new']], 'actions' => [['type' => 'change_stage', 'stage' => 'contacted']]]);

        // Created five days ago as "new"; it has since been won. Today's state would not match; its state then does.
        $lead = $this->lead(['created_at' => now()->subDays(5), 'status' => 'won']);
        $this->activity($lead, 'status_changed', ['from' => 'new', 'to' => 'contacted'], now()->subDays(4));
        $this->activity($lead, 'status_changed', ['from' => 'contacted', 'to' => 'won'], now()->subDays(2));

        $sim = $this->simulate($rule);

        $this->assertSame(1, $sim['leads_affected'], 'it was new when it arrived');
        $this->assertSame(1, $sim['stage_changes']['total']);
    }

    public function test_a_score_condition_uses_the_score_the_lead_had_then(): void
    {
        $rule = $this->rule(['conditions' => [['field' => 'score', 'operator' => 'gte', 'value' => 70]]]);
        $lead = $this->lead(['created_at' => now()->subDays(3)]);
        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['score' => 92])->saveQuietly());
        $this->activity($lead, 'score_changed', ['from' => 20, 'to' => 92, 'temperature' => 'hot'], now()->subDay());

        $this->assertSame(0, $this->simulate($rule)['leads_affected'], 'it scored 20 when it arrived, whatever it scores now');
    }

    public function test_quiet_hours_are_judged_at_the_hour_the_lead_arrived(): void
    {
        $this->asTenant($this->t['tenant'], fn () => TenantSetting::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)
            ->update(['quiet_hours_enabled' => true, 'quiet_hours_start' => '21:00', 'quiet_hours_end' => '08:00', 'timezone' => 'UTC']));
        $rule = $this->rule(['actions' => [['type' => 'send_whatsapp', 'purpose' => 'welcome']]]);

        $this->lead(['created_at' => now()->subDays(2)->setTime(23, 30)]);
        $this->lead(['created_at' => now()->subDays(2)->setTime(12, 0)]);

        $sim = $this->simulate($rule);

        $this->assertSame(2, $sim['leads_affected']);
        $this->assertSame(1, $sim['messages']['whatsapp']);
        $this->assertSame(['quiet_hours' => 1], $sim['messages']['held'], 'the midday lead is messaged, the late-night one waits');
    }

    public function test_the_days_cap_counts_only_what_had_been_sent_before_then(): void
    {
        $this->asTenant($this->t['tenant'], fn () => TenantSetting::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->update(['max_contacts_per_day' => 2]));
        $rule = $this->rule(['trigger' => 'lead.replied', 'actions' => [['type' => 'send_whatsapp', 'purpose' => 'reminder']]]);

        $lead = $this->lead(['created_at' => now()->subDays(4)]);
        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['first_response_at' => now()->subDays(3)])->saveQuietly());
        $send = fn ($at) => $this->asTenant($this->t['tenant'], function () use ($lead, $at) {
            $m = LeadMessage::create(['lead_id' => $lead->id, 'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Whatsapp, 'provider' => 'mock', 'message_type' => 'text', 'body' => 'x', 'status' => 'sent']);
            $m->forceFill(['created_at' => $at])->saveQuietly();
        });
        $send(now()->subDays(3)->subHours(3));
        $send(now()->subDays(3)->subHours(2));
        // Sent a day AFTER the reply: not part of the day the rule would have run on.
        $send(now()->subDays(2));

        $sim = $this->simulate($rule);

        $this->assertSame(['max_frequency' => 1], $sim['messages']['held'], 'two had gone that day already');
    }

    public function test_the_window_can_be_seven_or_thirty_days_and_the_chart_has_every_day(): void
    {
        $rule = $this->rule(['actions' => [['type' => 'change_stage', 'stage' => 'contacted']]]);
        $this->lead(['created_at' => now()->subDays(3)]);
        $this->lead(['created_at' => now()->subDays(20)]);

        $week = $this->simulate($rule);
        $month = $this->actingAs($this->t['admin'])->getJson("/api/v1/automation/rules/{$rule->uuid}/simulate?days=30")->assertOk()->json('data.simulation');

        $this->assertSame([7, 1], [$week['days'], $week['leads_affected']]);
        $this->assertSame([30, 2], [$month['days'], $month['leads_affected']]);
        $this->assertCount(7, $week['by_day']);
        $this->assertCount(30, $month['by_day']);
        $this->assertSame(1, array_sum(array_column($week['by_day'], 'leads')));
        $this->assertSame(7, $this->actingAs($this->t['admin'])->getJson("/api/v1/automation/rules/{$rule->uuid}/simulate?days=99")->json('data.simulation.days'), 'an odd window falls back to 7');
    }

    public function test_the_qualified_estimate_comes_from_the_workspaces_own_history_and_stays_silent_without_it(): void
    {
        $rule = $this->rule(['actions' => [['type' => 'send_whatsapp', 'purpose' => 'welcome']]]);
        $this->lead(['created_at' => now()->subDay()]);

        $thin = $this->simulate($rule)['qualified'];
        $this->assertNull($thin['estimated']);
        $this->assertSame('not_enough_history', $thin['reason']);

        // Twenty-plus messaged leads of which half qualified, twenty-plus not messaged of which a tenth did.
        foreach (range(1, 22) as $i) {
            $l = $this->lead(['created_at' => now()->subDays(2), 'status' => $i % 2 === 0 ? 'qualified' : 'contacted']);
            $this->asTenant($this->t['tenant'], fn () => LeadMessage::create(['lead_id' => $l->id, 'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Whatsapp, 'provider' => 'mock', 'message_type' => 'text', 'body' => 'x', 'status' => 'sent']));
        }
        foreach (range(1, 22) as $i) {
            $this->lead(['created_at' => now()->subDays(2), 'status' => $i % 10 === 0 ? 'qualified' : 'new']);
        }

        $rich = $this->simulate($rule)['qualified'];

        $this->assertNotNull($rich['estimated']);
        $this->assertGreaterThan($rich['rate_without'], $rich['rate_with_message']);
        $this->assertNull($rich['reason']);
    }
}
