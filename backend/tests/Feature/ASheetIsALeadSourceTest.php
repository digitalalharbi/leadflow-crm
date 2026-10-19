<?php

namespace Tests\Feature;

use App\Domain\Sheets\SheetImporter;
use App\Enums\IntegrationProvider;
use App\Enums\Role;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Project;
use App\Models\SheetSource;
use App\Models\SheetSyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A spreadsheet is an ordinary lead source.
 *
 * Plenty of teams run a campaign through a sheet somebody fills in by hand, and
 * today that ends with a CSV export once a week — by which time the leads at
 * the top are cold. The point of this is that such a lead is deduplicated,
 * attributed, scored, assigned and followed up exactly like one from an ad,
 * because it goes through the same door.
 *
 * Most of what is tested is the refusal to lose anything: a bad row must not
 * stop the import, and a failure that is ours must not mark somebody's lead as
 * rejected.
 */
class ASheetIsALeadSourceTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Integration $connection;

    private SheetSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Sheet Co');

        config([
            'integrations.google_sheets.client_id' => 'test-client',
            'integrations.google_sheets.client_secret' => 'test-secret',
        ]);

        $this->connection = $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => IntegrationProvider::GoogleSheets->value,
            'name' => 'Google Sheets',
            'status' => Integration::CONNECTED,
            'credentials' => [
                'access_token' => 'at-1',
                'refresh_token' => 'rt-1',
                'expires_at' => now()->addHour()->toIso8601String(),
            ],
            'is_active' => true,
        ]));

        $this->source = $this->asTenant($this->t['tenant'], fn () => SheetSource::create([
            'integration_id' => $this->connection->id,
            'spreadsheet_id' => 'sheet-abc',
            'spreadsheet_name' => 'عملاء الحملة',
            'sheet_name' => 'Leads',
            'header_row' => 1,
            'mapping' => ['full_name' => 'الاسم', 'phone' => 'الجوال', 'city' => 'المدينة'],
            'defaults' => ['platform' => 'meta', 'campaign_name' => 'حملة الشيت'],
        ]));
    }

    /** What the sheet currently contains, below the header. */
    private array $dataRows = [];

    /**
     * Google's API, registered once.
     *
     * `Http::fake()` adds stubs rather than replacing them, and the first match
     * wins — so calling it twice in one test leaves the first answer in place
     * and a "nothing new in the sheet" assertion quietly tests the old rows.
     */
    private function fakeSheet(array $dataRows, array $overrides = []): void
    {
        $this->dataRows = $dataRows;

        if ($overrides !== []) {
            Http::fake($overrides);

            return;
        }

        if ($this->sheetFaked) {
            return;
        }

        $this->sheetFaked = true;
        $headers = ['الاسم', 'الجوال', 'المدينة'];

        Http::fake([
            'sheets.googleapis.com/v4/spreadsheets/sheet-abc/values/*' => function ($request) use ($headers) {
                // The header read asks for row 1; everything else is data.
                if (str_contains(urldecode($request->url()), '!1:1')) {
                    return Http::response(['values' => [$headers]], 200);
                }

                return Http::response(['values' => $this->dataRows], 200);
            },
        ]);
    }

    private bool $sheetFaked = false;

    private function sync(string $trigger = 'scheduled'): SheetSyncRun
    {
        return $this->asTenant(
            $this->t['tenant'],
            fn () => app(SheetImporter::class)->sync($this->source, $this->t['tenant'], $trigger),
        );
    }

    // ------------------------------------------------------------ importing

    public function test_a_row_becomes_a_lead(): void
    {
        $this->fakeSheet([['سارة القحطاني', '0551234567', 'الرياض']]);

        $run = $this->sync();

        $lead = Lead::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('سارة القحطاني', $lead->full_name);
        $this->assertSame('+966551234567', $lead->phone_normalized);
        $this->assertSame('الرياض', $lead->city);
        $this->assertSame(1, $run->imported);
    }

    /**
     * The whole design: one pipeline. A sheet lead that is already in the
     * system re-engages rather than becoming a second copy, because the
     * duplicate matcher is the same one a webhook goes through.
     */
    public function test_a_row_for_somebody_we_already_know_does_not_duplicate_them(): void
    {
        $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => 'سارة القحطاني',
            'phone_normalized' => '+966551234567',
            'status' => 'contacted',
        ]));

        $this->fakeSheet([['سارة القحطاني', '0551234567', 'الرياض']]);

        $run = $this->sync();

        $this->assertSame(1, Lead::withoutGlobalScopes()->count());
        $this->assertSame(1, $run->duplicates);
        $this->assertSame(0, $run->imported);
    }

    public function test_the_sheet_stamps_its_campaign_on_what_it_brings(): void
    {
        $this->fakeSheet([['سارة', '0551234567', 'الرياض']]);

        $this->sync();

        $this->assertSame('حملة الشيت', Lead::withoutGlobalScopes()->first()->campaign_name);
    }

    /**
     * A sheet with a project column covers several projects, and stamping them
     * all with the source's default would put every lead on the wrong team.
     */
    public function test_a_project_named_in_the_row_beats_the_sources_default(): void
    {
        $narjis = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
        $other = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'الياسمين']));

        $this->source->forceFill([
            'mapping' => ['full_name' => 'الاسم', 'phone' => 'الجوال', 'project' => 'المدينة'],
            'defaults' => ['project_id' => $other->id],
        ])->save();

        $this->fakeSheet([['سارة', '0551234567', 'النرجس']]);

        $this->sync();

        $this->assertSame($narjis->id, Lead::withoutGlobalScopes()->first()->project_id);
    }

    // -------------------------------------------------------------- cursors

    public function test_the_second_sync_does_not_re_read_the_first_rows(): void
    {
        $this->fakeSheet([['سارة', '0551234567', 'الرياض']]);
        $this->sync();

        $this->assertSame(2, $this->source->fresh()->cursor_row);

        // Nothing new in the sheet; the next read starts past row 2.
        $this->fakeSheet([]);
        $second = $this->sync();

        $this->assertSame(0, $second->rows_seen);
        $this->assertSame(1, Lead::withoutGlobalScopes()->count());
    }

    /** Somebody leaves a gap in a sheet. It is crossed, not re-read forever. */
    public function test_a_blank_row_is_crossed_rather_than_retried(): void
    {
        $this->fakeSheet([[], ['سارة', '0551234567', 'الرياض']]);

        $run = $this->sync();

        $this->assertSame(1, $run->imported);
        $this->assertSame(0, $run->failed);
        $this->assertSame(3, $this->source->fresh()->cursor_row);
    }

    /**
     * The one that matters most. A row that can never be valid must not stop
     * the import — otherwise every sync for the rest of the year reads row 2,
     * fails, and the four thousand rows after it are never seen.
     */
    public function test_a_row_that_can_never_work_does_not_stop_the_rest(): void
    {
        $this->fakeSheet([
            ['بلا رقم', '', 'الرياض'],
            ['سارة', '0551234567', 'الرياض'],
            ['عمر', '0559876543', 'جدة'],
        ]);

        $run = $this->sync();

        $this->assertSame(2, $run->imported);
        $this->assertSame(1, $run->failed);
        $this->assertSame(4, $this->source->fresh()->cursor_row);
        $this->assertSame(2, $run->failures[0]['row']);
    }

    /** Enough to fix the sheet, not a second copy of the rows it refused. */
    public function test_the_reasons_rows_were_refused_are_capped(): void
    {
        $rows = array_fill(0, 40, ['بلا رقم', '', '']);
        $this->fakeSheet($rows);

        $run = $this->sync();

        $this->assertSame(40, $run->failed);
        $this->assertLessThanOrEqual(SheetSyncRun::MAX_RECORDED_FAILURES, count($run->failures));
    }

    /**
     * A failure that is ours is not the row's fault. The run stops where it is
     * and the cursor stays, so nobody's lead is marked rejected because our
     * end was busy — and the next sync reads the rows nobody has read.
     */
    public function test_our_own_failure_leaves_the_unread_rows_unread(): void
    {
        $this->fakeSheet([], [
            'sheets.googleapis.com/v4/spreadsheets/sheet-abc/values/*' => function ($request) {
                if (str_contains(urldecode($request->url()), '!1:1')) {
                    return Http::response(['values' => [['الاسم', 'الجوال', 'المدينة']]], 200);
                }

                return Http::response(['message' => 'backend error'], 500);
            },
        ]);

        $run = $this->sync();

        $this->assertSame(SheetSyncRun::FAILED, $run->status);
        $this->assertSame('unreachable', $run->error);
        $this->assertSame(0, $this->source->fresh()->cursor_row);
    }

    public function test_a_revoked_grant_is_reported_as_needing_a_person(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
            'sheets.googleapis.com/*' => Http::response(['values' => []], 200),
        ]);

        $this->connection->forceFill([
            'credentials' => ['refresh_token' => 'rt-1', 'expires_at' => now()->subHour()->toIso8601String()],
        ])->save();

        $run = $this->sync();

        $this->assertSame(SheetSyncRun::FAILED, $run->status);
        $this->assertSame('reauth_required', $this->connection->fresh()->last_error);
    }

    /** A tab with no header row cannot be mapped; importing it would invent meanings. */
    public function test_a_sheet_with_no_headers_is_refused_rather_than_guessed_at(): void
    {
        Http::fake(['sheets.googleapis.com/*' => Http::response(['values' => []], 200)]);

        $this->assertSame('no_headers', $this->sync()->error);
    }

    // ------------------------------------------------------------- the api

    public function test_a_sheet_without_a_phone_or_an_email_column_is_refused(): void
    {
        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/integrations/sheets', [
                'spreadsheet_id' => 'x', 'sheet_name' => 'Leads',
                'mapping' => ['full_name' => 'الاسم', 'city' => 'المدينة'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.mapping.0', 'sheets.needs_phone_or_email');
    }

    public function test_a_rep_may_not_connect_a_sheet(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->actingAs($rep)
            ->postJson('/api/v1/integrations/sheets', [
                'spreadsheet_id' => 'x', 'sheet_name' => 'Leads',
                'mapping' => ['phone' => 'الجوال'],
            ])
            ->assertForbidden();
    }

    public function test_one_workspace_never_sees_anothers_sheets(): void
    {
        $other = $this->provisionTenant('Somebody Else');

        $sources = $this->actingAs($other['admin'])
            ->getJson('/api/v1/integrations/sheets')
            ->assertOk()
            ->json('data.sources');

        $this->assertSame([], $sources);
    }

    public function test_the_screen_says_whether_the_platform_can_offer_this_at_all(): void
    {
        config(['integrations.google_sheets.client_id' => null]);

        $state = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/integrations/sheets')
            ->assertOk()
            ->json('data.connection');

        // Without an OAuth client nobody can connect, and that is ours to fix
        // rather than something to show a customer as their error.
        $this->assertFalse($state['oauth_configured']);
    }

    /**
     * The step that stops a mapping mistake becoming four thousand leads with
     * the city in the name field. Nothing is imported and no cursor moves.
     */
    public function test_a_preview_shows_the_mapping_before_anything_is_imported(): void
    {
        $this->fakeSheet([['سارة', '0551234567', 'الرياض']]);

        $preview = $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/integrations/sheets/preview', [
                'spreadsheet_id' => 'sheet-abc',
                'sheet_name' => 'Leads',
                'mapping' => ['full_name' => 'الاسم', 'phone' => 'الجوال'],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(['الاسم', 'الجوال', 'المدينة'], $preview['headers']);
        $this->assertSame('سارة', $preview['mapped'][0]['full_name']);
        $this->assertSame(0, Lead::withoutGlobalScopes()->count());
    }

    public function test_the_last_run_is_visible_without_opening_a_log(): void
    {
        $this->fakeSheet([['سارة', '0551234567', 'الرياض']]);
        $this->sync('manual');

        $source = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/integrations/sheets')
            ->assertOk()
            ->json('data.sources.0');

        $this->assertSame('manual', $source['last_run']['trigger']);
        $this->assertSame(1, $source['last_run']['imported']);
        $this->assertSame('connected', $source['health']['state']);
    }
}
