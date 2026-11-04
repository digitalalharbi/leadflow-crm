<?php

namespace Tests\Feature;

use App\Domain\Automation\ActionExecutor;
use App\Domain\Files\FileForTheLead;
use App\Enums\MessageStatus;
use App\Models\FileShare;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Services\Whatsapp\WhatsappSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\ExpectationInterface;
use Tests\Support\FakeMailer;
use Tests\TestCase;

/**
 * The right file, chosen from what the product knows, and sent by the channel
 * the lead will read.
 *
 * Two rules hold the whole thing up. A file that names a campaign, a stage or an
 * audience is only ever offered to a lead that matches — the winter brochure is
 * not a fallback for the summer campaign. And every choice carries its reasons,
 * so "why that one" can be answered by somebody who was not there.
 */
class FilesChooseThemselvesTest extends TestCase
{
    use RefreshDatabase;

    private FakeMailer $mailer;

    private array $t;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->mailer = $this->fakeMailer();

        $this->t = $this->provisionTenant('Choosing Co');
        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
    }

    private function file(string $name, array $attributes = []): ProjectFile
    {
        return $this->asTenant($this->t['tenant'], fn () => ProjectFile::create(array_merge([
            'project_id' => $this->project->id,
            'name' => $name,
            'kind' => 'brochure',
            'language' => null,
            'path' => 'project-files/'.uniqid().'.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 1024,
            'is_active' => true,
        ], $attributes)));
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'غادة الحربي',
            'email' => 'ghada@example.test',
            'phone_normalized' => '+9665'.random_int(10000000, 99999999),
            'status' => 'new',
            'project_id' => $this->project->id,
            'assigned_to' => $this->t['admin']->id,
        ], $attributes)));
    }

    private function pick(Lead $lead, ?string $kind = null): array
    {
        return $this->asTenant($this->t['tenant'], fn () => app(FileForTheLead::class)->pick($lead->fresh(), $kind));
    }

    // ------------------------------------------------------------- campaign

    public function test_a_campaign_file_goes_to_that_campaigns_leads(): void
    {
        $this->file('عام');
        $winter = $this->file('حملة الشتاء', ['campaign' => 'winter']);

        $this->assertSame($winter->id, $this->pick($this->lead(['campaign_name' => 'winter']))['file']->id);
    }

    public function test_the_campaign_match_ignores_case_and_spacing(): void
    {
        $winter = $this->file('حملة الشتاء', ['campaign' => ' Winter ']);

        $this->assertSame($winter->id, $this->pick($this->lead(['campaign_name' => 'winter']))['file']->id);
    }

    /** The winter brochure is not a fallback for the summer campaign. */
    public function test_a_campaign_file_is_never_offered_to_another_campaign(): void
    {
        $general = $this->file('عام');
        $this->file('حملة الشتاء', ['campaign' => 'winter']);

        $this->assertSame($general->id, $this->pick($this->lead(['campaign_name' => 'summer']))['file']->id);
        $this->assertSame($general->id, $this->pick($this->lead(['campaign_name' => null]))['file']->id);
    }

    public function test_when_only_other_campaigns_files_exist_nothing_is_chosen_and_it_says_so(): void
    {
        $this->file('حملة الشتاء', ['campaign' => 'winter']);

        $choice = $this->pick($this->lead(['campaign_name' => 'summer']));

        $this->assertNull($choice['file']);
        $this->assertSame('no_files_for_this_lead', $choice['reason']);
    }

    // ---------------------------------------------------------------- stage

    public function test_a_stage_file_waits_for_that_stage(): void
    {
        $general = $this->file('عام');
        $offer = $this->file('عرض', ['kind' => 'brochure', 'stages' => ['negotiation']]);

        $this->assertSame($general->id, $this->pick($this->lead(['status' => 'new']))['file']->id);
        $this->assertSame($offer->id, $this->pick($this->lead(['status' => 'negotiation']))['file']->id);
    }

    public function test_the_stage_suggests_the_kind_when_the_lead_has_said_nothing(): void
    {
        $brochure = $this->file('كتيب');
        $prices = $this->file('الأسعار', ['kind' => 'price_list']);
        $offer = $this->file('العرض', ['kind' => 'offer']);

        $this->assertSame($brochure->id, $this->pick($this->lead(['status' => 'new']))['file']->id);
        $this->assertSame($prices->id, $this->pick($this->lead(['status' => 'qualified']))['file']->id);
        $this->assertSame($offer->id, $this->pick($this->lead(['status' => 'negotiation']))['file']->id);
    }

    public function test_a_stage_with_no_matching_kind_falls_back_to_the_brochure(): void
    {
        $brochure = $this->file('كتيب');

        $this->assertSame($brochure->id, $this->pick($this->lead(['status' => 'qualified']))['file']->id);
    }

    // ------------------------------------------------------------- lead type

    public function test_an_investor_gets_the_deck_and_says_why(): void
    {
        $this->file('كتيب');
        $deck = $this->file('عرض المستثمر', ['kind' => 'investment_deck']);

        $choice = $this->pick($this->lead(['purchase_purpose' => 'investment']));

        $this->assertSame($deck->id, $choice['file']->id);
        $this->assertContains('investment_purpose', $choice['reasons']);
    }

    public function test_an_audience_file_is_only_for_that_audience(): void
    {
        $general = $this->file('عام');
        $this->file('للمستثمرين', ['audience' => 'investor']);

        $this->assertSame($general->id, $this->pick($this->lead(['purchase_purpose' => 'residence']))['file']->id);
        $this->assertSame($general->id, $this->pick($this->lead())['file']->id);
    }

    public function test_someone_who_needs_financing_gets_the_guide(): void
    {
        $this->file('كتيب');
        $guide = $this->file('دليل التمويل', ['kind' => 'financing_guide']);

        $this->assertSame($guide->id, $this->pick($this->lead(['payment_method' => 'financing']))['file']->id);
    }

    // -------------------------------------------------------------- ranking

    public function test_the_most_specific_file_wins(): void
    {
        $this->file('عام');
        $this->file('للشتاء', ['campaign' => 'winter']);
        $best = $this->file('للشتاء والمستثمر', ['campaign' => 'winter', 'audience' => 'investor', 'kind' => 'brochure']);

        $lead = $this->lead(['campaign_name' => 'winter', 'purchase_purpose' => 'investment']);

        // The investor deck kind is wanted; none exists, so a brochure is sent —
        // and of the brochures the one that matches the most is chosen.
        $this->assertSame($best->id, $this->pick($lead)['file']->id);
    }

    public function test_the_language_still_decides_between_otherwise_equal_files(): void
    {
        $this->file('عربي', ['language' => 'ar']);
        $english = $this->file('English', ['language' => 'en']);

        $this->assertSame($english->id, $this->pick($this->lead(['phone_normalized' => '+447911123456']))['file']->id);
    }

    public function test_the_reasons_name_what_decided(): void
    {
        $this->file('للشتاء', ['campaign' => 'winter', 'stages' => ['new']]);

        $reasons = $this->pick($this->lead(['campaign_name' => 'winter']))['reasons'];

        $this->assertContains('campaign', $reasons);
        $this->assertContains('stage_new', $reasons);
    }

    public function test_an_explicit_kind_overrides_the_suggestion(): void
    {
        $this->file('كتيب');
        $prices = $this->file('الأسعار', ['kind' => 'price_list']);

        $choice = $this->pick($this->lead(['status' => 'new']), 'price_list');

        $this->assertSame($prices->id, $choice['file']->id);
        $this->assertContains('asked_for', $choice['reasons']);
    }

    public function test_a_paused_file_is_never_chosen(): void
    {
        $this->file('موقوف', ['is_active' => false]);

        $this->assertNull($this->pick($this->lead())['file']);
    }

    public function test_a_file_uploaded_before_targeting_existed_is_chosen_exactly_as_before(): void
    {
        $old = $this->file('قديم');

        $this->assertSame($old->id, $this->pick($this->lead(['campaign_name' => 'anything', 'status' => 'negotiation']))['file']->id);
    }

    // ------------------------------------------------------------ management

    public function test_a_file_can_be_targeted_when_it_is_uploaded_and_changed_later(): void
    {
        $upload = UploadedFile::fake()->create('prices.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->t['admin'])->post('/api/v1/projects/'.$this->project->uuid.'/files', [
            'file' => $upload, 'kind' => 'price_list', 'campaign' => 'winter',
            'stages' => ['qualified', 'negotiation'], 'audience' => 'investor',
        ], ['Accept' => 'application/json'])->assertCreated();

        $response->assertJsonPath('data.file.campaign', 'winter')
            ->assertJsonPath('data.file.audience', 'investor')
            ->assertJsonPath('data.file.stages', ['qualified', 'negotiation']);

        $this->actingAs($this->t['admin'])->patchJson(
            '/api/v1/projects/'.$this->project->uuid.'/files/'.$response->json('data.file.id'),
            ['campaign' => null, 'stages' => []],
        )->assertOk()->assertJsonPath('data.file.campaign', null);
    }

    public function test_a_stage_that_is_not_a_stage_is_refused(): void
    {
        $file = $this->file('x');

        $this->actingAs($this->t['admin'])->patchJson(
            '/api/v1/projects/'.$this->project->uuid.'/files/'.$file->uuid,
            ['stages' => ['sleeping']],
        )->assertStatus(422);
    }

    public function test_the_lead_screen_gets_the_suggestion_with_its_reasons(): void
    {
        $this->file('للشتاء', ['campaign' => 'winter']);
        $lead = $this->lead(['campaign_name' => 'winter']);

        $this->actingAs($this->t['admin'])->getJson("/api/v1/leads/{$lead->uuid}/files")
            ->assertOk()->assertJsonPath('data.suggested.name', 'للشتاء')
            ->assertJsonPath('data.reasons.0', 'campaign');
    }

    // -------------------------------------------------------------- delivery

    public function test_a_rep_can_send_the_chosen_file_by_email(): void
    {
        $this->file('كتيب النرجس');
        $lead = $this->lead();

        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$lead->uuid}/files/send", ['channel' => 'email'])
            ->assertOk()->assertJsonPath('data.status', 'sent');

        $mail = $this->mailer->sent[0] ?? null;
        $this->assertNotNull($mail);
        $this->assertStringContainsString('/f/', (string) $mail->text);
        $this->assertSame('كتيب النرجس', $mail->subject);
        $this->assertSame(1, FileShare::withoutGlobalScopes()->count());
    }

    public function test_the_email_carries_the_per_lead_tracked_link(): void
    {
        $this->file('كتيب');
        $lead = $this->lead();

        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$lead->uuid}/files/send", ['channel' => 'email'])->assertOk();

        $share = FileShare::withoutGlobalScopes()->firstOrFail();
        $this->assertStringContainsString($share->token, (string) $this->mailer->sent[0]->text);
    }

    /**
     * Routing, not Meta: the document upload itself is covered by the live
     * provider tests. What matters here is that the rep's button and the rule
     * reach the *same* sender, with the tracked link in the caption.
     */
    private function whatsappTakes(): ExpectationInterface
    {
        return $this->mock(WhatsappSender::class)
            ->shouldReceive('sendFile')
            ->once()
            ->withArgs(fn ($lead, $file, $caption) => is_string($caption) && str_contains($caption, '/f/'))
            ->andReturn((new LeadMessage)->forceFill(['status' => MessageStatus::Sent]));
    }

    public function test_the_whatsapp_document_goes_through_the_same_door(): void
    {
        $this->file('كتيب');
        $lead = $this->lead();
        $this->whatsappTakes();

        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$lead->uuid}/files/send", ['channel' => 'whatsapp'])
            ->assertOk()->assertJsonPath('data.status', 'sent');
    }

    public function test_when_whatsapp_is_not_connected_the_link_still_stands(): void
    {
        $this->file('كتيب');
        $lead = $this->lead();

        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$lead->uuid}/files/send", ['channel' => 'whatsapp'])
            ->assertStatus(422);

        $this->assertSame(1, FileShare::withoutGlobalScopes()->count());
    }

    public function test_an_unsubscribed_lead_is_not_sent_the_file_but_the_link_stands(): void
    {
        $this->file('كتيب');
        $lead = $this->lead();
        $lead->forceFill(['email_opt_out_at' => now()])->saveQuietly();

        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$lead->uuid}/files/send", ['channel' => 'email'])
            ->assertStatus(422);

        $this->assertSame([], $this->mailer->sent);
        // For a rep to hand over some other way.
        $this->assertSame(1, FileShare::withoutGlobalScopes()->count());
    }

    public function test_a_lead_with_no_files_gets_a_reason_not_a_crash(): void
    {
        $lead = $this->lead(['project_id' => null]);

        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$lead->uuid}/files/send", ['channel' => 'email'])->assertStatus(422);
    }

    public function test_a_named_file_must_belong_to_the_leads_project(): void
    {
        $other = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'آخر']));
        $foreign = $this->asTenant($this->t['tenant'], fn () => ProjectFile::create([
            'project_id' => $other->id, 'name' => 'x', 'kind' => 'brochure', 'path' => 'p.pdf',
            'mime' => 'application/pdf', 'size_bytes' => 1, 'is_active' => true,
        ]));
        $lead = $this->lead();

        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$lead->uuid}/files/send", ['channel' => 'email', 'file' => $foreign->uuid])
            ->assertStatus(422);
    }

    public function test_one_workspace_cannot_send_files_to_anothers_lead(): void
    {
        $this->file('كتيب');
        $lead = $this->lead();
        $other = $this->provisionTenant('Elsewhere');

        $this->actingAs($other['admin'])->postJson("/api/v1/leads/{$lead->uuid}/files/send", ['channel' => 'email'])->assertNotFound();
    }

    // ------------------------------------------------------------ automation

    private function perform(Lead $lead, array $action): array
    {
        return $this->asTenant($this->t['tenant'], fn () => app(ActionExecutor::class)->execute($lead->fresh(), $action + ['type' => 'share_file']));
    }

    public function test_a_rule_sends_the_chosen_file_by_email_and_says_why(): void
    {
        $this->file('كتيب');
        $this->file('للشتاء', ['campaign' => 'winter']);

        $result = $this->perform($this->lead(['campaign_name' => 'winter']), ['channel' => 'email']);

        $this->assertTrue($result['ok'], $result['description']);
        $this->assertStringContainsString('للشتاء', $result['description']);
        $this->assertStringContainsString('مخصص لحملته', $result['description']);
        $this->assertNotEmpty($this->mailer->sent);
    }

    public function test_a_rule_defaults_to_whatsapp(): void
    {
        $this->file('كتيب');
        $this->whatsappTakes();

        $result = $this->perform($this->lead(), []);

        $this->assertTrue($result['ok'], $result['description']);
        $this->assertSame([], $this->mailer->sent);
    }

    public function test_a_rule_that_only_prepares_the_link_sends_nothing(): void
    {
        $this->file('كتيب');

        $result = $this->perform($this->lead(), ['send' => false, 'channel' => 'email']);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $this->mailer->sent);
        $this->assertSame(1, FileShare::withoutGlobalScopes()->count());
    }

    public function test_a_rule_reports_when_the_projects_files_are_for_somebody_else(): void
    {
        $this->file('للشتاء', ['campaign' => 'winter']);

        $result = $this->perform($this->lead(['campaign_name' => 'summer']), ['channel' => 'email']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('لا ينتمي', $result['description']);
    }

    public function test_a_rule_reports_instead_of_throwing_when_the_lead_unsubscribed(): void
    {
        $this->file('كتيب');
        $lead = $this->lead();
        $lead->forceFill(['email_opt_out_at' => now()])->saveQuietly();

        $result = $this->perform($lead, ['channel' => 'email']);

        $this->assertSame('skipped', $result['result'] ?? null);
        $this->assertStringContainsString('الرابط', $result['description']);
    }

    public function test_the_rule_editor_accepts_the_channel(): void
    {
        $this->actingAs($this->t['admin'])->postJson('/api/v1/automation/rules', [
            'name' => 'أرسل الكتيب', 'trigger' => 'lead.created', 'conditions' => [], 'condition_match' => 'all',
            'actions' => [['type' => 'share_file', 'channel' => 'email']],
        ])->assertCreated();

        $this->actingAs($this->t['admin'])->postJson('/api/v1/automation/rules', [
            'name' => 'x', 'trigger' => 'lead.created', 'conditions' => [], 'condition_match' => 'all',
            'actions' => [['type' => 'share_file', 'channel' => 'carrier_pigeon']],
        ])->assertStatus(422);
    }
}
