<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AutomationRule;
use App\Models\FileShare;
use App\Models\FollowUp;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LostReason;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The pilot's smoke test: one lead, the whole way, then one that is lost.
 *
 *   Lead → Source → Dedup → Project → Assignment → WhatsApp → File → Call
 *        → Follow-up → Appointment → Won/Lost → Revenue
 *
 * This is the same path somebody walks by hand on the first day with live
 * credentials, written down so that a failure on that day is a failure of the
 * *credentials* and not a discovery about the product. It runs here against the
 * demo providers, which means it proves the joins and proves nothing about
 * Meta, Twilio or Google — those are what the readiness screen is for, and no
 * step below is allowed to be read as evidence of them.
 *
 * Each step asserts a consequence somebody downstream relies on, not that a
 * call returned 200.
 */
class PilotSmokePathTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'pilot-secret-0123456789abcdefghij';

    private const APP_SECRET = 'pilot-app-secret';

    private const PHONE_NUMBER_ID = '1555000777';

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->t = $this->provisionTenant('Pilot Realty');
    }

    public function test_a_lead_goes_from_an_ad_to_revenue_and_a_lost_one_does_not_count(): void
    {
        $tenant = $this->t['tenant'];
        $admin = $this->t['admin'];

        // ---- what a workspace sets up once -------------------------------
        $reps = [
            $this->makeUser($tenant, Role::Sales, ['name' => 'مندوب أول']),
            $this->makeUser($tenant, Role::Sales, ['name' => 'مندوب ثاني']),
        ];

        $project = $this->asTenant($tenant, fn () => Project::create(['name' => 'النرجس']));

        Storage::disk('local')->put('project-files/brochure.pdf', '%PDF-1.4 brochure');

        $brochure = $this->asTenant($tenant, fn () => ProjectFile::create([
            'project_id' => $project->id,
            'name' => 'كتيب النرجس',
            'kind' => 'brochure',
            'path' => 'project-files/brochure.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 17,
        ]));

        $this->asTenant($tenant, fn () => Integration::create([
            'provider' => 'whatsapp',
            'name' => 'WhatsApp Business',
            'status' => Integration::CONNECTED,
            'config' => ['phone_number_id' => self::PHONE_NUMBER_ID],
            // No access token: the demo provider answers, as on a developer machine.
            'credentials' => ['verify_token' => 'v', 'app_secret' => self::APP_SECRET],
            'is_active' => true,
        ]));

        $endpoint = $this->asTenant($tenant, function () use ($project, $tenant) {
            $endpoint = WebhookEndpoint::where('tenant_id', $tenant->id)->first();
            $endpoint->forceFill(['secret_hash' => Hash::make(self::SECRET)])->saveQuietly();

            // Project mapping for pushed leads is a property of the source: an
            // ad platform cannot know our internal ids, so the integration says
            // which project its leads belong to.
            $endpoint->integration->forceFill([
                'defaults' => ['project_id' => $project->id],
            ])->save();

            return $endpoint;
        });

        $this->asTenant($tenant, fn () => AutomationRule::create([
            'name' => 'إسناد ومتابعة أولى',
            'trigger' => 'lead.created',
            'priority' => 10,
            'conditions' => [],
            'actions' => [
                ['type' => 'assign', 'strategy' => 'round_robin'],
                ['type' => 'schedule_follow_up', 'in_minutes' => 30, 'note' => 'اتصال أول'],
            ],
        ]));

        // ---- 1-2. The lead arrives, and says where from -------------------
        $arrive = fn (string $name, string $phone) => $this->withHeader('X-Webhook-Secret', self::SECRET)
            ->postJson("/api/v1/integrations/webhooks/{$endpoint->key}/leads", [
                'full_name' => $name,
                'phone' => $phone,
                'city' => 'الرياض',
                'platform' => 'meta',
                'campaign_name' => 'حملة النرجس',
            ]);

        $arrive('سلطان المطيري', '0551234567')->assertSuccessful();

        $lead = $this->asTenant($tenant, fn () => Lead::where('phone_normalized', '+966551234567')->first());

        $this->assertNotNull($lead, 'SOURCE: the lead never landed');
        $this->assertSame('meta', $lead->platform?->value ?? $lead->platform);
        $this->assertDatabaseHas('lead_source_events', ['lead_id' => $lead->id]);

        // ---- 3. Dedup: the same person again is not a second lead ---------
        $arrive('سلطان المطيري', '+966 55 123 4567')->assertSuccessful();

        $this->assertSame(
            1,
            $this->asTenant($tenant, fn () => Lead::where('phone_normalized', '+966551234567')->count()),
            'DEDUP: the same phone in another format made a second lead',
        );

        // ---- 4. Project --------------------------------------------------
        $lead->refresh();
        $this->assertSame($project->id, $lead->project_id, 'PROJECT: the source default did not attach the project');

        // ---- 5. Assignment ----------------------------------------------
        $this->assertContains(
            $lead->assigned_to,
            collect($reps)->pluck('id')->all(),
            'ASSIGNMENT: nobody was assigned',
        );

        $owner = collect($reps)->firstWhere('id', $lead->assigned_to);

        // ---- 6. WhatsApp: out, then the customer answers -----------------
        $this->actingAs($owner)->postJson("/api/v1/leads/{$lead->uuid}/messages", [
            'body' => 'مرحبًا سلطان، معك فريق النرجس.',
        ])->assertCreated();

        $this->assertDatabaseHas('lead_messages', [
            'lead_id' => $lead->id,
            'direction' => 'outbound',
            'status' => 'sent',
        ]);

        $raw = json_encode($this->inbound('966551234567', 'نعم مهتم، أرسل لي التفاصيل', 'wamid.pilot.1'), JSON_UNESCAPED_UNICODE);

        $this->call('POST', '/api/v1/integrations/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, self::APP_SECRET),
        ], $raw)->assertSuccessful()->assertJsonPath('data.messages', 1);

        $lead->refresh();
        $this->assertNotNull($lead->first_response_at, 'WHATSAPP: the reply did not register as a response');

        // ---- 7. File: the link is issued and opening it is counted -------
        $share = $this->actingAs($owner)->postJson("/api/v1/leads/{$lead->uuid}/files/share", [
            'file' => $brochure->uuid,
            'channel' => 'whatsapp',
        ])->assertOk()->json('data.share');

        $this->get(parse_url($share['url'], PHP_URL_PATH))->assertSuccessful();

        $this->assertSame(
            1,
            (int) $this->asTenant($tenant, fn () => FileShare::where('lead_id', $lead->id)->value('opens')),
            'FILE: opening the link was not counted',
        );

        // ---- 8. Call, and what came of it --------------------------------
        $call = $this->actingAs($owner)->postJson("/api/v1/leads/{$lead->uuid}/contact", ['channel' => 'call'])
            ->assertOk()
            ->json('data.call');

        // No telephony account here, so the rep dials — and that is said.
        $this->assertSame('manual', $call['mode']);

        $this->actingAs($owner)->postJson("/api/v1/leads/{$lead->uuid}/calls/{$call['id']}", [
            'status' => 'completed',
            'outcome' => 'interested',
            'duration_seconds' => 240,
        ])->assertOk();

        // ---- 9. Follow-up -------------------------------------------------
        $followUp = $this->asTenant($tenant, fn () => FollowUp::where('lead_id', $lead->id)
            ->where('status', 'pending')->first());

        $this->assertNotNull($followUp, 'FOLLOW-UP: the rule left no next step');

        $this->actingAs($owner)->postJson("/api/v1/follow-ups/{$followUp->uuid}/complete")->assertSuccessful();

        // ---- 10. Appointment ----------------------------------------------
        $this->actingAs($owner)->postJson('/api/v1/appointments', [
            'lead_id' => $lead->uuid,
            'scheduled_at' => now()->addDays(2)->toIso8601String(),
            'location' => 'مكتب المبيعات',
        ])->assertCreated();

        $this->assertSame('appointment', $lead->fresh()->status->value, 'APPOINTMENT: the stage did not move');

        // ---- 11. Won, with a value ----------------------------------------
        $this->actingAs($owner)->postJson("/api/v1/leads/{$lead->uuid}/status", [
            'status' => 'won',
            'deal_value' => 1250000,
        ])->assertOk();

        $this->assertSame(125000000, $lead->fresh()->deal_value_minor);

        // ---- 12. Lost: a second lead, closed with a reason ----------------
        $arrive('نورة العتيبي', '0557778888')->assertSuccessful();

        $second = $this->asTenant($tenant, fn () => Lead::where('phone_normalized', '+966557778888')->first());
        $reason = $this->asTenant($tenant, fn () => LostReason::first());

        $this->actingAs($admin)->postJson("/api/v1/leads/{$second->uuid}/status", [
            'status' => 'lost',
            'lost_reason_id' => $reason->uuid,
        ])->assertOk();

        // ---- Revenue: what was sold, and only that ------------------------
        $rows = collect(
            $this->actingAs($admin)->getJson('/api/v1/reports/campaign-quality')->assertOk()->json('data.rows'),
        );

        $campaign = $rows->firstWhere('campaign', 'حملة النرجس');

        $this->assertNotNull($campaign, 'REVENUE: the campaign is not in the report');
        $this->assertEquals(1250000.0, $campaign['revenue'], 'REVENUE: not the value of the won deal');
        $this->assertSame(2, $campaign['leads'], 'REVENUE: the duplicate was counted as a lead');

        $summary = $this->actingAs($admin)->getJson('/api/v1/dashboard')->assertOk()->json('data.summary');

        $this->assertSame(1, $summary['won']);
        $this->assertSame(1, $summary['lost']);
    }

    private function inbound(string $from, string $text, string $id): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '900900',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '966500000001', 'phone_number_id' => self::PHONE_NUMBER_ID],
                        'contacts' => [['profile' => ['name' => 'سلطان'], 'wa_id' => $from]],
                        'messages' => [[
                            'from' => $from,
                            'id' => $id,
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ];
    }
}
