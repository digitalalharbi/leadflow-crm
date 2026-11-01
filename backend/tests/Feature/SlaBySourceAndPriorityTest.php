<?php

namespace Tests\Feature;

use App\Domain\Automation\ProjectSettings;
use App\Domain\Automation\Thresholds;
use App\Domain\Sla\SlaRules;
use App\Domain\Sla\SlaSweeper;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Some leads cannot wait as long as others: a Meta lead in fifteen minutes, a priority lead in ten. The strictest
 * rule that applies wins, and a rule never loosens the workspace's own target.
 */
class SlaBySourceAndPriorityTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Rules Co');
        $this->t['tenant']->settings->forceFill([
            'first_contact_target_minutes' => 60,
            'sla_remind_after_minutes' => 60,
            'sla_escalate_after_minutes' => 0,
            'sla_reassign_after_minutes' => 0,
        ])->save();
    }

    private function lead(int $ageMinutes, array $attributes = [], bool $priority = false): Lead
    {
        $rep = $this->makeUser($this->t['tenant']);

        return $this->asTenant($this->t['tenant'], function () use ($ageMinutes, $attributes, $rep, $priority) {
            $lead = Lead::create($attributes + ['full_name' => 'L', 'phone_normalized' => '+9665000'.random_int(10000, 99999), 'status' => 'new', 'assigned_to' => $rep->id]);
            $lead->forceFill(['created_at' => now()->subMinutes($ageMinutes), 'is_priority' => $priority])->saveQuietly();

            return $lead->fresh();
        });
    }

    private function rules(array $rules): void
    {
        $this->actingAs($this->t['admin'])->patchJson('/api/v1/settings', ['sla_rules' => $rules])->assertOk();
    }

    public function test_the_strictest_rule_that_applies_wins_and_none_loosens_the_target(): void
    {
        $this->rules(['by_source' => ['meta' => 15, 'website' => 120], 'priority_minutes' => 10]);

        $this->assertSame(15, ProjectSettings::firstContactTarget($this->lead(0, ['platform' => 'meta']), 60));
        $this->assertSame(10, ProjectSettings::firstContactTarget($this->lead(0, ['platform' => 'meta'], true), 60));
        // A looser source rule does not loosen the workspace's hour.
        $this->assertSame(60, ProjectSettings::firstContactTarget($this->lead(0, ['platform' => 'website']), 60));
        $this->assertSame(60, ProjectSettings::firstContactTarget($this->lead(0, ['platform' => 'tiktok']), 60));
    }

    public function test_the_sweeper_reminds_a_meta_lead_on_its_own_clock(): void
    {
        $this->rules(['by_source' => ['meta' => 15]]);

        // Twenty minutes old: late for Meta (15), on time for everyone else (60).
        $meta = $this->lead(20, ['platform' => 'meta']);
        $site = $this->lead(20, ['platform' => 'website']);

        app(SlaSweeper::class)->sweep($this->t['tenant']->fresh());

        $this->assertNotNull($meta->fresh()->sla_reminded_at);
        $this->assertNull($site->fresh()->sla_reminded_at);
    }

    public function test_the_settings_api_keeps_only_what_it_understands(): void
    {
        $this->actingAs($this->t['admin'])->patchJson('/api/v1/settings', ['sla_rules' => ['by_source' => ['Meta Ads!' => 5]]])->assertUnprocessable();
        $this->actingAs($this->t['admin'])->patchJson('/api/v1/settings', ['sla_rules' => ['by_source' => ['meta' => 0]]])->assertUnprocessable();
        $this->actingAs($this->t['admin'])->patchJson('/api/v1/settings', ['sla_rules' => ['other' => 1]])->assertUnprocessable();

        $this->rules(['by_source' => ['meta' => 15], 'priority_minutes' => null]);
        $this->assertSame(['by_source' => ['meta' => 15], 'priority_minutes' => null], $this->actingAs($this->t['admin'])->getJson('/api/v1/settings')->json('data.settings.sla_rules'));

        $this->actingAs($this->t['admin'])->patchJson('/api/v1/settings', ['sla_rules' => null])->assertOk();
        Thresholds::flush();
        $this->assertSame(['by_source' => [], 'priority_minutes' => null], SlaRules::forTenant($this->t['tenant']->id));
    }

    public function test_a_rep_cannot_change_the_rules(): void
    {
        $this->actingAs($this->makeUser($this->t['tenant']))->patchJson('/api/v1/settings', ['sla_rules' => ['priority_minutes' => 1]])->assertForbidden();
    }
}
