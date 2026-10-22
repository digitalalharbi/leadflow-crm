<?php

namespace Tests\Feature;

use App\Domain\Agents\FollowUpAgent;
use App\Domain\Agents\LeadsAgent;
use App\Domain\Agents\Orchestrator;
use App\Domain\Agents\SalesAgent;
use App\Models\AutomationActionLog;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Routine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Agents are not chatbots here: they run on a schedule behind the rules, and
 * what they do lands in the same Action History as everything else.
 */
class AgentsWorkBehindTheRulesTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Agents Co');
    }

    private function lead(array $attributes): Lead
    {
        return $this->asTenant($this->t['tenant'], function () use ($attributes) {
            $phone = '+96655'.random_int(1000000, 9999999);
            $lead = Lead::create(['full_name' => 'عميل', 'phone' => $phone, 'phone_normalized' => $phone, 'status' => 'new', 'assigned_to' => $this->t['admin']->id, 'email' => $attributes['email'] ?? null]);
            $lead->forceFill($attributes)->saveQuietly();

            return $lead->fresh();
        });
    }

    public function test_a_hot_lead_nobody_called_is_raised_once_a_day(): void
    {
        $lead = $this->lead(['is_priority' => true, 'temperature' => 'hot', 'automation_state' => 'handed_off', 'needs_human_reason' => 'replied', 'handed_off_at' => now()->subMinutes(90)]);

        $first = $this->asTenant($this->t['tenant'], fn () => app(SalesAgent::class)->hotLeadReview());
        $second = $this->asTenant($this->t['tenant'], fn () => app(SalesAgent::class)->hotLeadReview());

        $this->assertSame(1, $first['owners']);
        $this->assertSame(1, $first['managers'], 'an hour and a half is long enough to tell the managers');
        $this->assertSame(0, $second['owners']);
        $this->assertSame(1, $this->asTenant($this->t['tenant'], fn () => AutomationActionLog::where('lead_id', $lead->id)->where('result', 'needs_human')->count()));
    }

    public function test_a_lead_somebody_already_called_is_left_alone(): void
    {
        $this->lead(['is_priority' => true, 'temperature' => 'hot', 'automation_state' => 'handed_off', 'needs_human_reason' => 'replied', 'handed_off_at' => now()->subMinutes(90), 'last_contact_at' => now()->subMinutes(10)]);

        $raised = $this->asTenant($this->t['tenant'], fn () => app(SalesAgent::class)->hotLeadReview());

        $this->assertSame(0, $raised['owners']);
    }

    public function test_a_lead_forgotten_for_a_week_goes_back_to_automation(): void
    {
        $this->travel(-8)->days();
        $stale = $this->lead(['email' => 'stale@example.com']);
        $this->travelBack();

        $this->asTenant($this->t['tenant'], fn () => MessageTemplate::where('purpose', 'nurture')->where('channel', 'email')->update(['is_active' => true]));

        $revived = $this->asTenant($this->t['tenant'], fn () => app(FollowUpAgent::class)->staleLeadReview());

        $this->assertSame(1, $revived);
        $row = $this->asTenant($this->t['tenant'], fn () => AutomationActionLog::where('lead_id', $stale->id)->first());
        $this->assertSame('start_nurture', $row->action_type);
        $this->assertSame('routine.stale_lead_review', $row->trigger);
    }

    public function test_the_new_routines_run_through_the_orchestrator(): void
    {
        foreach (['hot_lead_review', 'stale_lead_review'] as $routine) {
            $this->asTenant($this->t['tenant'], fn () => app(Orchestrator::class)->run($routine));
            $this->assertSame('ok', $this->asTenant($this->t['tenant'], fn () => Routine::where('key', $routine)->value('last_status')));
        }
    }

    public function test_a_campaign_with_many_leads_and_few_real_buyers_is_a_warning(): void
    {
        $campaign = $this->asTenant($this->t['tenant'], fn () => Campaign::create(['name' => 'حملة ضعيفة', 'platform' => 'meta', 'external_id' => 'c-weak', 'status' => 'active']));

        $this->asTenant($this->t['tenant'], function () use ($campaign) {
            for ($i = 0; $i < 55; $i++) {
                Lead::create(['full_name' => "ع{$i}", 'phone' => '+9665510'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'phone_normalized' => '+9665510'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                    'status' => 'new', 'campaign_name' => 'حملة ضعيفة', 'campaign_ref_id' => $campaign->id, 'platform' => 'meta']);
            }
        });

        $findings = $this->asTenant($this->t['tenant'], fn () => app(LeadsAgent::class)->review());

        $this->assertContains('low_qualified_rate', array_map(fn ($f) => $f->key, $findings));

        // A warning to people — the campaign itself is untouched.
        $this->assertSame('active', $campaign->fresh()->status);
    }
}
