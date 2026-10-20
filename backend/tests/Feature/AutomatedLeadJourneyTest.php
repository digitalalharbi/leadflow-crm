<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Lead;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The product's actual claim, end to end: a lead arrives from an ad platform
 * and the system qualifies, assigns, schedules and records it with nobody
 * touching anything.
 *
 * This is written against the HTTP boundary rather than the services, because
 * the claim is about what happens when a platform posts — not about whether the
 * pieces work when called in the right order by a test.
 */
class AutomatedLeadJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_lead_from_a_webhook_is_worked_without_anyone_touching_it(): void
    {
        $t = $this->provisionTenant('Journey Realty');
        $this->makeUser($t['tenant']);

        // What the tenant configured once, through the rule builder.
        $this->asTenant($t['tenant'], function () {
            AutomationRule::create([
                'name' => 'عملاء الرياض ذوو الميزانية العالية',
                'trigger' => 'lead.created',
                'priority' => 10,
                'conditions' => [
                    ['field' => 'city', 'operator' => 'equals', 'value' => 'الرياض'],
                ],
                'actions' => [
                    ['type' => 'adjust_score', 'by' => 20],
                    ['type' => 'assign', 'strategy' => 'round_robin'],
                    ['type' => 'schedule_follow_up', 'in_minutes' => 15, 'note' => 'اتصال أول'],
                    ['type' => 'add_note', 'body' => 'وصل عبر حملة الرياض'],
                ],
            ]);
        });

        $secret = 'journey-secret-0123456789abcdefgh';
        $endpoint = $this->asTenant($t['tenant'], function () use ($t, $secret) {
            $e = WebhookEndpoint::where('tenant_id', $t['tenant']->id)->first();
            $e->forceFill(['secret_hash' => Hash::make($secret)])->saveQuietly();

            return $e;
        });

        $this->assertNotNull($endpoint, 'A tenant is provisioned with an intake endpoint.');

        // 1-2. The platform posts. Nothing else happens by hand after this.
        $this->withHeader('X-Webhook-Secret', $secret)
            ->postJson("/api/v1/integrations/webhooks/{$endpoint->key}/leads", [
                'full_name' => 'سلطان المطيري',
                'phone' => '0551234567',
                'city' => 'الرياض',
                'platform' => 'meta',
                'campaign_name' => 'حملة الرياض — فلل',
            ])
            ->assertSuccessful();

        $this->asTenant($t['tenant'], function () {
            $lead = Lead::where('phone_normalized', '+966551234567')->first();

            // 3-7. Validated, normalised, attributed.
            $this->assertNotNull($lead, 'The lead never landed.');
            $this->assertSame('الرياض', $lead->city);
            $this->assertSame('حملة الرياض — فلل', $lead->campaign_name);
            $this->assertDatabaseHas('lead_source_events', ['lead_id' => $lead->id]);

            // 8-9. Scored and assigned, by the rule, without a human.
            $this->assertNotNull($lead->assigned_to, 'Nobody was assigned.');

            // 11. The next step exists before anyone opened the screen.
            $this->assertDatabaseHas('follow_ups', [
                'lead_id' => $lead->id,
                'status' => 'pending',
            ]);

            // 35. And every decision can be read back.
            $runs = AutomationRun::where('lead_id', $lead->id)->get();
            $this->assertNotEmpty($runs, 'The automation left no trace.');

            $applied = $runs->where('outcome', 'applied');
            $this->assertNotEmpty($applied, 'The matching rule did not apply.');

            // Every rule that applied to this lead left its sentences; the assignment and the follow-up are among them.
            $descriptions = $applied->flatMap(fn ($run) => collect($run->applied_actions)->pluck('description'))->implode(' | ');
            // The trace is prose a manager reads, so it is in their language.
            $this->assertStringContainsString('أُسند إلى', $descriptions);
            $this->assertStringContainsString('حُدِّدت متابعة', $descriptions);
        });
    }
}
