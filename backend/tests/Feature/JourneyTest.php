<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\FollowUp;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class JourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_step_counts_leads_once_and_the_funnel_only_narrows(): void
    {
        $t = $this->provisionTenant('Journey Co');

        $this->asTenant($t['tenant'], function () use ($t) {
            $leads = collect(range(1, 4))->map(fn ($i) => Lead::create([
                'full_name' => 'عميل '.$i, 'phone_normalized' => '+96650000200'.$i, 'status' => 'new',
            ]));

            // Two runs on the same lead are still one lead.
            $rule = AutomationRule::create([
                'name' => 'ترحيب', 'trigger' => 'lead.created', 'conditions' => [], 'actions' => [], 'is_enabled' => true,
            ]);
            foreach ([0, 0, 1] as $i) {
                AutomationRun::create(['lead_id' => $leads[$i]->id, 'automation_rule_id' => $rule->id, 'trigger' => 'lead.created', 'outcome' => 'applied']);
            }
            AutomationRun::create(['lead_id' => $leads[2]->id, 'automation_rule_id' => $rule->id, 'trigger' => 'lead.created', 'outcome' => 'failed']);

            DB::table('lead_messages')->insert([
                'tenant_id' => $t['tenant']->id, 'lead_id' => $leads[0]->id, 'uuid' => (string) Str::uuid(),
                'direction' => 'outbound', 'channel' => 'whatsapp', 'provider' => 'mock', 'message_type' => 'text',
                'body' => 'مرحبا', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now(),
            ]);

            FollowUp::create(['lead_id' => $leads[0]->id, 'assigned_to' => $t['admin']->id, 'created_by' => $t['admin']->id, 'due_at' => now()->addDay(), 'status' => 'pending']);
        });

        $steps = collect($this->actingAs($t['admin'])->getJson('/api/v1/dashboard/journey?days=7')->assertOk()->json('data.steps'))->keyBy('key');

        $this->assertSame(4, $steps['arrived']['count']);
        $this->assertSame(2, $steps['automated']['count']);
        $this->assertSame(1, $steps['reached']['count']);
        $this->assertSame(1, $steps['followed']['count']);
        $this->assertSame(0, $steps['waiting']['count']);
    }

    public function test_a_rep_sees_only_the_journey_of_their_own_leads(): void
    {
        $t = $this->provisionTenant('Scoped Journey');
        $rep = $this->makeUser($t['tenant']);

        $this->asTenant($t['tenant'], function () use ($rep) {
            Lead::create(['full_name' => 'لي', 'phone_normalized' => '+966500003001', 'status' => 'new', 'assigned_to' => $rep->id]);
            Lead::create(['full_name' => 'لغيري', 'phone_normalized' => '+966500003002', 'status' => 'new']);
        });

        $this->assertSame(1, $this->actingAs($rep)->getJson('/api/v1/dashboard/journey')->assertOk()->json('data.steps.0.count'));
    }
}
