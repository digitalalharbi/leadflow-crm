<?php

namespace Tests\Feature;

use App\Domain\Files\FileForTheLead;
use App\Domain\Files\ShareFile;
use App\Enums\Role;
use App\Models\FileShare;
use App\Models\Lead;
use App\Models\LeadEngagement;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Services\Leads\LeadScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The right file reaches the lead, and we learn whether they opened it.
 *
 * A rep sending a brochure today finds it in WhatsApp, on a shared drive, or in
 * an email from March — and sends the Arabic one to an English speaker about as
 * often as not. Worse, once sent it vanishes: nobody knows whether the customer
 * opened it, which is the single most useful thing a sales team could know
 * before deciding who to call.
 */
class TheRightFileFindsTheLeadTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->t = $this->provisionTenant('Files Co');
        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
    }

    private function file(array $attributes = []): ProjectFile
    {
        return $this->asTenant($this->t['tenant'], fn () => ProjectFile::create(array_merge([
            'project_id' => $this->project->id,
            'name' => 'brochure.pdf',
            'kind' => 'brochure',
            'language' => null,
            'path' => 'project-files/'.uniqid().'.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 1024,
        ], $attributes)));
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل',
            'phone_normalized' => '+966551234567',
            'status' => 'new',
            'project_id' => $this->project->id,
        ], $attributes)));
    }

    private function pick(Lead $lead, ?string $kind = null): array
    {
        return $this->asTenant($this->t['tenant'], fn () => app(FileForTheLead::class)->pick($lead, $kind));
    }

    // ------------------------------------------------------------- choosing

    /**
     * The one that happens most: an English-speaking customer and two
     * brochures. Sending the Arabic one is the ordinary mistake this removes.
     */
    public function test_it_sends_the_brochure_in_the_language_the_number_says(): void
    {
        $arabic = $this->file(['name' => 'ar.pdf', 'language' => 'ar']);
        $english = $this->file(['name' => 'en.pdf', 'language' => 'en']);

        $this->assertSame($english->id, $this->pick($this->lead(['phone_normalized' => '+447700900123']))['file']->id);
        $this->assertSame($arabic->id, $this->pick($this->lead(['phone_normalized' => '+966551234567']))['file']->id);
    }

    /** A price list of numbers suits everybody; demanding a translation of it
     *  would leave half the customers without one at all. */
    public function test_a_file_with_no_language_suits_anybody(): void
    {
        $any = $this->file(['name' => 'any.pdf', 'language' => null]);

        $this->assertSame($any->id, $this->pick($this->lead(['phone_normalized' => '+905321234567']))['file']->id);
    }

    public function test_somebody_buying_to_invest_gets_the_numbers(): void
    {
        $this->file(['name' => 'brochure.pdf']);
        $deck = $this->file(['name' => 'deck.pdf', 'kind' => 'investment_deck']);

        $choice = $this->pick($this->lead(['purchase_purpose' => 'investment']));

        $this->assertSame($deck->id, $choice['file']->id);
        $this->assertSame('investment_purpose', $choice['reason']);
    }

    public function test_somebody_who_needs_financing_gets_the_guide(): void
    {
        $this->file(['name' => 'brochure.pdf']);
        $guide = $this->file(['name' => 'financing.pdf', 'kind' => 'financing_guide']);

        $choice = $this->pick($this->lead(['payment_method' => 'financing']));

        $this->assertSame($guide->id, $choice['file']->id);
        $this->assertSame('needs_financing', $choice['reason']);
    }

    /**
     * A project with a deck in one language and a brochure in all of them
     * should send the brochure to the rest, not stay silent.
     */
    public function test_it_falls_back_to_a_brochure_rather_than_to_nothing(): void
    {
        $brochure = $this->file(['name' => 'brochure.pdf']);

        $choice = $this->pick($this->lead(['purchase_purpose' => 'investment']));

        $this->assertSame($brochure->id, $choice['file']->id);
    }

    public function test_a_project_with_no_files_says_so_rather_than_guessing(): void
    {
        $choice = $this->pick($this->lead());

        $this->assertNull($choice['file']);
        $this->assertSame('no_files', $choice['reason']);
    }

    public function test_a_lead_with_no_project_has_nothing_to_send(): void
    {
        $this->file();

        $this->assertNull($this->pick($this->lead(['project_id' => null]))['file']);
    }

    /** Switched off means not sent, not "sent anyway because it was first". */
    public function test_an_inactive_file_is_never_chosen(): void
    {
        $this->file(['is_active' => false]);

        $this->assertNull($this->pick($this->lead())['file']);
    }

    /** "Why did it send that file" has to be answerable by somebody who was not here. */
    public function test_every_choice_carries_its_reason(): void
    {
        $this->file(['language' => 'ar']);

        $this->assertSame('language_ar', $this->pick($this->lead())['reason']);
    }

    // -------------------------------------------------------------- sharing

    public function test_a_share_is_one_link_per_lead_however_often_it_is_sent(): void
    {
        $file = $this->file();
        $lead = $this->lead();

        $first = $this->asTenant($this->t['tenant'], fn () => app(ShareFile::class)->forLead($file, $lead));
        $second = $this->asTenant($this->t['tenant'], fn () => app(ShareFile::class)->forLead($file, $lead));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, FileShare::withoutGlobalScopes()->count());
    }

    public function test_the_token_is_never_handed_back_in_a_listing(): void
    {
        $file = $this->file();
        $lead = $this->lead();
        $share = $this->asTenant($this->t['tenant'], fn () => app(ShareFile::class)->forLead($file, $lead));

        $body = $this->actingAs($this->t['admin'])
            ->getJson("/api/v1/leads/{$lead->uuid}/shares")
            ->assertOk()
            ->json();

        $this->assertStringNotContainsString($share->token, json_encode($body));
    }

    // --------------------------------------------------------- the opening

    private function open(FileShare $share)
    {
        return $this->get('/f/'.$share->token);
    }

    private function sharedFile(): array
    {
        $file = $this->file();
        Storage::disk('local')->put($file->path, 'pretend-pdf');
        $lead = $this->lead();

        return [$file, $lead, $this->asTenant($this->t['tenant'], fn () => app(ShareFile::class)->forLead($file, $lead))];
    }

    public function test_opening_the_link_serves_the_file_and_counts_the_open(): void
    {
        [, , $share] = $this->sharedFile();

        $this->open($share)->assertOk();

        $fresh = FileShare::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(1, $fresh->opens);
        $this->assertNotNull($fresh->first_opened_at);
    }

    /** The open is the signal; the second open is a different signal. */
    public function test_a_second_open_is_counted_separately(): void
    {
        [, , $share] = $this->sharedFile();

        $this->open($share);
        $this->open($share);

        $this->assertSame(2, FileShare::withoutGlobalScopes()->first()->opens);
        $this->assertSame(2, LeadEngagement::withoutGlobalScopes()
            ->where('type', LeadEngagement::FILE_OPENED)->count());
    }

    public function test_a_token_nobody_issued_is_a_404(): void
    {
        $this->get('/f/'.str_repeat('x', 40))->assertNotFound();
    }

    public function test_a_file_switched_off_stops_answering_its_old_links(): void
    {
        [$file, , $share] = $this->sharedFile();

        $file->forceFill(['is_active' => false])->save();

        $this->open($share)->assertNotFound();
    }

    // ------------------------------------------------------------- scoring

    /**
     * The point of recording the open: behaviour moves the score, so the
     * customer who is reading about the project rises above the one who is not.
     */
    public function test_opening_a_brochure_raises_the_score(): void
    {
        [, $lead, $share] = $this->sharedFile();

        $before = $this->asTenant($this->t['tenant'], fn () => app(LeadScoringService::class)->evaluate($lead))['score'];

        $this->open($share);

        $after = $this->asTenant($this->t['tenant'], fn () => app(LeadScoringService::class)->evaluate($lead->fresh()))['score'];

        $this->assertGreaterThan($before, $after);
    }

    /** And it says so, because a score nobody can explain is one nobody trusts. */
    public function test_the_score_says_the_brochure_was_opened(): void
    {
        [, $lead, $share] = $this->sharedFile();
        $this->open($share);

        $reasons = $this->asTenant($this->t['tenant'], fn () => app(LeadScoringService::class)->evaluate($lead->fresh()))['reasons'];

        $this->assertContains('file_opened', array_column($reasons, 'key'));
    }

    /**
     * A lead who opens a link eleven times is interested, not eleven times more
     * interested. Without a ceiling the loudest signal in the product would be
     * a mail client that prefetches images.
     */
    public function test_engagement_is_capped(): void
    {
        [, $lead, $share] = $this->sharedFile();

        for ($i = 0; $i < 12; $i++) {
            $this->open($share);
        }

        $reasons = $this->asTenant($this->t['tenant'], fn () => app(LeadScoringService::class)->evaluate($lead->fresh()))['reasons'];

        $engagement = collect($reasons)
            ->whereIn('key', ['file_opened', 'file_opened_again'])
            ->sum('points');

        $this->assertLessThanOrEqual((int) config('scoring.engagement.max'), $engagement);
    }

    /** Last March is history; this morning is a phone call. */
    public function test_an_old_engagement_stops_counting(): void
    {
        $lead = $this->lead();

        $this->asTenant($this->t['tenant'], fn () => LeadEngagement::create([
            'lead_id' => $lead->id,
            'type' => LeadEngagement::FILE_OPENED,
            'happened_at' => now()->subDays(LeadEngagement::RECENT_DAYS + 5),
        ]));

        $reasons = $this->asTenant($this->t['tenant'], fn () => app(LeadScoringService::class)->evaluate($lead))['reasons'];

        $this->assertNotContains('file_opened', array_column($reasons, 'key'));
    }

    // ------------------------------------------------------------- the api

    public function test_a_manager_uploads_a_file_against_the_project(): void
    {
        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/projects/{$this->project->uuid}/files", [
                'file' => UploadedFile::fake()->create('brochure.pdf', 64, 'application/pdf'),
                'kind' => 'brochure',
                'language' => 'ar',
            ])
            ->assertCreated()
            ->assertJsonPath('data.file.kind', 'brochure');

        $this->assertSame(1, ProjectFile::withoutGlobalScopes()->count());
    }

    public function test_a_rep_may_not_change_what_a_project_sells_with(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->actingAs($rep)
            ->postJson("/api/v1/projects/{$this->project->uuid}/files", [
                'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
                'kind' => 'brochure',
            ])
            ->assertForbidden();
    }

    /** A rep sends files; that is the job. */
    public function test_a_rep_may_send_one(): void
    {
        $file = $this->file();
        $lead = $this->lead(['assigned_to' => $this->t['admin']->id]);
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $lead->forceFill(['assigned_to' => $rep->id])->save();

        $this->actingAs($rep)
            ->postJson("/api/v1/leads/{$lead->uuid}/files/share", ['file' => $file->uuid, 'channel' => 'whatsapp'])
            ->assertOk()
            ->assertJsonPath('data.share.opens', 0);
    }

    public function test_a_file_from_another_project_cannot_be_sent(): void
    {
        $other = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'الياسمين']));
        $theirs = $this->file(['project_id' => $other->id]);
        $lead = $this->lead();

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$lead->uuid}/files/share", ['file' => $theirs->uuid])
            ->assertNotFound();
    }

    public function test_one_workspace_never_sees_anothers_files(): void
    {
        $this->file();
        $other = $this->provisionTenant('Somebody Else');

        $this->actingAs($other['admin'])
            ->getJson("/api/v1/projects/{$this->project->uuid}/files")
            ->assertNotFound();
    }
}
