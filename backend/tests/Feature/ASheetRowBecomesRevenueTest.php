<?php

namespace Tests\Feature;

use App\Domain\Sheets\SheetImporter;
use App\Enums\IntegrationProvider;
use App\Jobs\RunScheduledAction;
use App\Models\AutomationActionLog;
use App\Models\Campaign;
use App\Models\ConversionExport;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\ProjectQuestion;
use App\Models\ScheduledAction;
use App\Models\SheetSource;
use App\Services\Whatsapp\InboundWhatsappProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeMailer;
use Tests\TestCase;

/**
 * The whole chain, once, with nobody pushing it along until it is a person's
 * turn:
 *
 *   new Google Sheet row → lead → normalised → deduplicated → attributed →
 *   journey starts → WhatsApp → email → brochure → qualification questions →
 *   reply → score → priority → sales queue → call → appointment → won →
 *   revenue → campaign feedback (and the conversion going back to the ad
 *   platform) — with the Action History holding a row for every automated
 *   step.
 *
 * Every step runs on the product's real code. Only the outside world is a
 * fake, at the HTTP edge: Google's Sheets API, Meta's WhatsApp Cloud API, and
 * the mail transport. Passing here is "Code Verified", not "Live Verified" —
 * that needs a real sheet, a real number and a real mailbox.
 */
class ASheetRowBecomesRevenueTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private array $sentToWhatsapp = [];

    private FakeMailer $mailer;

    public function test_one_sheet_row_becomes_revenue_on_its_campaign_with_every_step_recorded(): void
    {
        Storage::fake('local');
        $this->mailer = $this->fakeMailer();
        Queue::fake([RunScheduledAction::class]);
        config(['mail.default' => 'smtp']);

        $this->t = $this->provisionTenant('Chain Co');
        $tenant = $this->t['tenant'];

        // ---------------------------------------------------------------- the workspace

        $project = $this->asTenant($tenant, function () {
            $project = Project::create(['name' => 'النرجس', 'starting_price' => 900000]);
            ProjectFile::create(['project_id' => $project->id, 'name' => 'بروشور النرجس', 'kind' => 'brochure', 'path' => 'project-files/narjis.pdf', 'mime' => 'application/pdf', 'size_bytes' => 2048, 'is_active' => true]);
            ProjectQuestion::create(['project_id' => $project->id, 'key' => 'budget', 'label' => 'ما ميزانيتك التقريبية؟', 'type' => 'text', 'is_active' => true, 'sort_order' => 1]);
            ProjectQuestion::create(['project_id' => $project->id, 'key' => 'timeline', 'label' => 'متى تنوي الشراء؟', 'type' => 'text', 'is_active' => true, 'sort_order' => 2]);

            return $project;
        });

        $this->actingAs($this->t['admin'])->postJson("/api/v1/projects/{$project->uuid}/automation-pack", ['service' => 'فلل', 'language' => 'ar'])->assertOk();

        $whatsapp = $this->asTenant($tenant, fn () => Integration::create([
            'provider' => 'whatsapp', 'name' => 'WhatsApp Business', 'status' => Integration::CONNECTED,
            'config' => ['phone_number_id' => '1555000999'], 'credentials' => ['access_token' => 'token', 'verify_token' => 'v', 'app_secret' => 's'], 'is_active' => true,
        ]));

        // Meta-approved templates, as a live workspace registers them.
        $this->asTenant($tenant, fn () => MessageTemplate::where('channel', 'whatsapp')->whereNotNull('purpose')
            ->update(['meta_template_name' => 'approved_v1', 'meta_language' => 'ar', 'meta_parameters' => []]));

        $sheets = $this->asTenant($tenant, fn () => Integration::create([
            'provider' => IntegrationProvider::GoogleSheets->value, 'name' => 'Google Sheets', 'status' => Integration::CONNECTED,
            'credentials' => ['access_token' => 'at', 'refresh_token' => 'rt', 'expires_at' => now()->addHour()->toIso8601String()], 'is_active' => true,
        ]));

        $headers = ['id', 'created_time', 'ad_id', 'ad_name', 'adset_id', 'adset_name', 'campaign_id', 'campaign_name', 'form_id', 'form_name', 'platform', 'هل_تفضل_فيلا_أم_شقة؟', 'full_name', 'phone_number', 'email'];
        $row = ['l:9001', '2026-10-22T10:00:00+0300', 'ad-1', 'فيديو الواجهة', 'as-1', 'الرياض 30-45', 'c-1', 'النرجس — أكتوبر', 'f-1', 'نموذج النرجس', 'fb', 'فيلا', 'أحمد السبيعي', 'p:0551234567', 'ahmed@example.com'];
        $rows = [$row];

        Http::fake([
            'sheets.googleapis.com/v4/spreadsheets/meta-leads/values/*' => function ($request) use (&$rows, $headers) {
                return str_contains(urldecode($request->url()), "'!1:1")
                    ? Http::response(['values' => [$headers]], 200)
                    : Http::response(['values' => $rows], 200);
            },
            'graph.facebook.com/*' => function ($request) {
                $this->sentToWhatsapp[] = $request->data();

                return Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]], 200);
            },
        ]);

        $source = $this->asTenant($tenant, fn () => SheetSource::create(['integration_id' => $sheets->id, 'spreadsheet_id' => 'meta-leads', 'spreadsheet_name' => 'Meta Lead Sheet', 'sheet_name' => 'Leads', 'header_row' => 1]));

        // ---------------------------------------------------------------- 1. the row arrives

        $this->asTenant($tenant, fn () => app(SheetImporter::class)->sync($source->refresh(), $tenant));

        // The same row again (a re-sync, a second copy) is still one lead.
        $rows = [$row, $row];
        $this->asTenant($tenant, fn () => app(SheetImporter::class)->sync($source->refresh(), $tenant));

        $lead = $this->asTenant($tenant, fn () => Lead::with('campaignRef')->sole());

        $this->assertSame('+966551234567', $lead->phone_normalized, 'normalised');
        $this->assertSame('facebook', $lead->platform);
        $this->assertSame($project->id, $lead->project_id, 'project from the campaign name');
        $this->assertSame('as-1', $lead->adset_id);
        $this->assertSame('l:9001', $lead->provider_lead_id);
        $this->assertNotNull($lead->campaignRef, 'attributed to its campaign');
        $campaign = $lead->campaignRef;

        // ---------------------------------------------------------------- 2. automation, with nobody

        $history = fn () => $this->asTenant($tenant, fn () => AutomationActionLog::where('lead_id', $lead->id)->orderBy('id')->get());

        $done = $history()->where('result', AutomationActionLog::COMPLETED)->pluck('action_type')->all();
        $this->assertContains('send_whatsapp', $done, 'WhatsApp welcome');
        $this->assertContains('send_email', $done, 'email welcome');
        $this->assertContains('share_file', $done, 'brochure');
        $this->assertNotEmpty($this->mailer->sent, 'the welcome email reached the door');
        $this->assertNotEmpty($this->sentToWhatsapp);

        $lead = $this->asTenant($tenant, fn () => $lead->fresh());
        $this->assertSame(Lead::AUTOMATION_NURTURING, $lead->automation_state);
        $this->assertNotNull($lead->first_contact_at, 'first contact made by automation, not a person');
        $this->assertNull($this->actingAs($this->t['admin'])->getJson('/api/v1/work/next')->json('data.lead'), 'nobody is asked to do what automation is doing');

        // ---------------------------------------------------------------- 3. the questions

        $ask = ScheduledAction::withoutGlobalScopes()->where('lead_id', $lead->id)->get()->first(fn ($s) => $s->action['type'] === 'ask_qualification');
        app()->call([new RunScheduledAction($ask->id), 'handle']);
        $this->assertSame(AutomationActionLog::COMPLETED, $history()->firstWhere('scheduled_action_id', $ask->id)->result);

        // ---------------------------------------------------------------- 4. the reply

        $scoreBefore = (int) $lead->score;
        $this->asTenant($tenant, fn () => app(InboundWhatsappProcessor::class)->process($whatsapp, ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '1555000999'],
            'messages' => [['from' => '966551234567', 'id' => 'wamid.reply.1', 'type' => 'text', 'text' => ['body' => '1. مليون ونصف 2. خلال شهرين'], 'timestamp' => (string) time()]],
        ]]]]]]));

        $lead = $this->asTenant($tenant, fn () => $lead->fresh());
        $this->assertGreaterThan($scoreBefore, (int) $lead->score, 're-scored');
        $this->assertSame('replied', $lead->needs_human_reason);
        $this->assertTrue($history()->where('result', AutomationActionLog::WAITING)->isEmpty(), 'no reminder goes to somebody who answered');

        // ---------------------------------------------------------------- 5. qualified → priority → sales

        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$lead->uuid}/status", ['status' => 'qualified'])->assertOk();

        $lead = $this->asTenant($tenant, fn () => $lead->fresh());
        $this->assertTrue($lead->is_priority, 'priority');

        $queue = $this->actingAs($this->t['admin'])->getJson('/api/v1/work/next')->assertOk()->json('data');
        $this->assertSame($lead->uuid, $queue['lead']['id'], 'first in the sales queue');
        $this->assertContains('replied', $queue['signals']);

        // ---------------------------------------------------------------- 6. call → appointment → won

        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$lead->uuid}/contact", ['channel' => 'call'])->assertSuccessful();
        $this->actingAs($this->t['admin'])->postJson('/api/v1/work/outcome', ['lead_id' => $lead->uuid, 'outcome' => 'meeting_booked'])->assertSuccessful();
        $this->assertSame('appointment', $this->asTenant($tenant, fn () => $lead->fresh()->status->value));

        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$lead->uuid}/status", ['status' => 'won', 'deal_value' => 1250000])->assertOk();

        // ---------------------------------------------------------------- 7. revenue → campaign feedback

        $metrics = $this->actingAs($this->t['admin'])->getJson("/api/v1/campaigns/{$campaign->uuid}")->assertOk()->json('data.campaign');
        $this->assertSame(1, $metrics['leads']);
        $this->assertSame(1, $metrics['qualified']);
        $this->assertSame(1, $metrics['appointments']);
        $this->assertSame(1, $metrics['won']);
        $this->assertEquals(1250000, $metrics['revenue']);

        // And the ad platform is told, step by step, so its algorithm learns
        // which people became buyers.
        $exports = $this->asTenant($tenant, fn () => ConversionExport::where('lead_id', $lead->id)->pluck('value', 'event'));
        $this->assertEqualsCanonicalizing(['qualified', 'appointment', 'won'], $exports->keys()->all());
        $this->assertEquals(1250000, $exports['won']);

        // ---------------------------------------------------------------- the proof

        $entries = $this->actingAs($this->t['admin'])->getJson("/api/v1/automation/history?lead={$lead->uuid}")->assertOk()->json('data.entries');
        $kinds = collect($entries)->map(fn ($e) => $e['action'].':'.$e['result'])->all();

        foreach (['send_whatsapp:completed', 'send_email:completed', 'share_file:completed', 'ask_qualification:completed', 'adjust_score:completed', 'hand_off:needs_human', 'set_priority:completed'] as $step) {
            $this->assertContains($step, $kinds, "the history shows {$step}");
        }

        $this->assertSame(0, $this->asTenant($tenant, fn () => LeadMessage::where('lead_id', $lead->id)->where('status', 'failed')->count()), 'nothing failed along the way');
    }
}
