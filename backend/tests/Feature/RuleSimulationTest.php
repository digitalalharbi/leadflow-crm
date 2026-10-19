<?php

namespace Tests\Feature;

use App\Jobs\RunScheduledAction;
use App\Models\AutomationActionLog;
use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\Project;
use App\Models\ScheduledAction;
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
}
