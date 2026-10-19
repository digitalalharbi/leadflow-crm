<?php

namespace Tests\Feature;

use App\Domain\Sheets\SheetImporter;
use App\Enums\IntegrationProvider;
use App\Jobs\SyncSheetSource;
use App\Models\CustomField;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Project;
use App\Models\SheetSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A lead sheet is connected and nobody maps anything.
 *
 * The workspace is about to connect three sheets — Meta's, TikTok's,
 * Snapchat's — and every one names its columns differently and carries its own
 * form questions. These tests hold the promise that a new row becomes a whole
 * lead on its own: every known column in its field, every unknown one kept,
 * the campaign, ad set, ad and form it came through, and the project worked out
 * from the campaign's name.
 */
class AnyLeadSheetMapsItselfTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Integration $connection;

    /** Meta's export, past column Z: two custom questions and a dozen padding columns. */
    private array $headers;

    private array $dataRows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Mapped Co');

        $this->headers = array_merge(
            ['id', 'created_time', 'ad_id', 'ad_name', 'adset_id', 'adset_name', 'campaign_id', 'campaign_name',
                'form_id', 'form_name', 'is_organic', 'platform', 'ما_نوع_الوحدة_المطلوبة؟', 'متى_تنوي_الشراء؟'],
            array_map(fn ($n) => "extra_{$n}", range(1, 14)),
            ['full_name', 'phone_number', 'email', 'city'],
        );

        $this->connection = $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => IntegrationProvider::GoogleSheets->value,
            'name' => 'Google Sheets',
            'status' => Integration::CONNECTED,
            'credentials' => ['access_token' => 'at', 'refresh_token' => 'rt', 'expires_at' => now()->addHour()->toIso8601String()],
            'is_active' => true,
        ]));

        Http::fake([
            'sheets.googleapis.com/v4/spreadsheets/meta-sheet/values/*' => function ($request) {
                if (str_contains(urldecode($request->url()), "'!1:1")) {
                    return Http::response(['values' => [$this->headers]], 200);
                }

                return Http::response(['values' => $this->dataRows], 200);
            },
        ]);
    }

    private function row(array $values): array
    {
        $row = array_fill(0, count($this->headers), '');

        foreach ($values as $header => $value) {
            $row[array_search($header, $this->headers, true)] = $value;
        }

        return $row;
    }

    private function source(array $attributes = []): SheetSource
    {
        return $this->asTenant($this->t['tenant'], fn () => SheetSource::create(array_merge([
            'integration_id' => $this->connection->id,
            'spreadsheet_id' => 'meta-sheet',
            'spreadsheet_name' => 'Meta Lead Sheet',
            'sheet_name' => 'Leads',
            'header_row' => 1,
        ], $attributes)));
    }

    /** Reloaded inside the workspace: outside it the tenant scope hides the connection. */
    private function sync(SheetSource $source): void
    {
        $this->asTenant($this->t['tenant'], fn () => app(SheetImporter::class)->sync($source->refresh(), $this->t['tenant']));
    }

    public function test_a_meta_export_becomes_a_whole_lead_with_nothing_mapped_by_hand(): void
    {
        $gardens = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'Riyadh Gardens', 'status' => 'active']));

        $this->dataRows = [$this->row([
            'id' => 'l:1203', 'created_time' => '2026-10-20T09:00:00+0300',
            'ad_id' => 'ad-9', 'ad_name' => 'Video 1', 'adset_id' => 'as-4', 'adset_name' => 'Riyadh 25-45',
            'campaign_id' => 'c-77', 'campaign_name' => 'Riyadh Gardens – October',
            'form_id' => 'f-3', 'form_name' => 'Gardens form', 'is_organic' => 'false', 'platform' => 'fb',
            'ما_نوع_الوحدة_المطلوبة؟' => 'فيلا', 'متى_تنوي_الشراء؟' => 'خلال ٣ أشهر',
            'extra_14' => 'far right', 'full_name' => 'سارة العتيبي', 'phone_number' => 'p:+966551234567',
            'email' => 'sara@example.com', 'city' => 'الرياض',
        ])];

        $source = $this->source();
        $this->sync($source);

        $lead = $this->asTenant($this->t['tenant'], fn () => Lead::with('customFieldValues.field')->firstOrFail());

        // Everything to the right of column Z arrived.
        $this->assertSame('سارة العتيبي', $lead->full_name);
        $this->assertSame('sara@example.com', $lead->email);
        $this->assertSame('+966551234567', $lead->phone);

        $this->assertSame('facebook', $lead->platform);
        $this->assertSame('c-77', $lead->campaign_id);
        $this->assertSame('as-4', $lead->adset_id);
        $this->assertSame('Riyadh 25-45', $lead->adset_name);
        $this->assertSame('ad-9', $lead->ad_id);
        $this->assertSame('f-3', $lead->form_id);
        $this->assertSame('Gardens form', $lead->form_name);
        $this->assertSame('l:1203', $lead->provider_lead_id);

        // The campaign named the project; nobody had to.
        $this->assertSame($gardens->id, $lead->project_id);

        // The form's own questions became fields, under their own names.
        $answers = $lead->customFieldValues->mapWithKeys(fn ($v) => [$v->field->label => $v->value]);
        $this->assertSame('فيلا', $answers['ما_نوع_الوحدة_المطلوبة؟']);
        $this->assertSame('خلال ٣ أشهر', $answers['متى_تنوي_الشراء؟']);
        $this->assertSame('far right', $answers['extra_14']);

        // And the row itself is kept, header by header.
        $this->assertSame('Riyadh 25-45', $lead->provider_payload['values']['adset_name']);
        $this->assertSame(2, $lead->provider_payload['row']);

        $source->refresh();
        $this->assertSame('phone_number', $source->mapping['phone']);
        $this->assertContains('ما_نوع_الوحدة_المطلوبة؟', array_keys($source->mapping_meta['custom']));
    }

    public function test_the_same_lead_arriving_twice_through_the_platform_id_is_one_lead(): void
    {
        $row = ['id' => 'l:55', 'full_name' => 'خالد', 'phone_number' => '+966550000001', 'campaign_name' => 'Any'];
        $this->dataRows = [$this->row($row), $this->row($row)];

        $this->sync($this->source());

        $this->assertSame(1, $this->asTenant($this->t['tenant'], fn () => Lead::count()));
    }

    public function test_a_column_added_to_the_sheet_later_is_picked_up_without_anybody_remapping(): void
    {
        $source = $this->source();
        $this->dataRows = [$this->row(['full_name' => 'أ', 'phone_number' => '+966550000002'])];
        $this->sync($source);

        $this->headers[] = 'سؤال جديد';
        $this->dataRows = [$this->row(['full_name' => 'ب', 'phone_number' => '+966550000003', 'سؤال جديد' => 'نعم'])];
        $this->sync($source);

        $lead = $this->asTenant($this->t['tenant'], fn () => Lead::where('full_name', 'ب')->with('customFieldValues.field')->firstOrFail());
        $this->assertSame('نعم', $lead->customFieldValues->firstWhere('field.label', 'سؤال جديد')?->value);
    }

    public function test_a_choice_a_person_made_survives_the_columns_changing(): void
    {
        $this->headers = ['name', 'phone', 'whatsapp'];
        $this->dataRows = [['علي', '0500000000', '+966551112223']];

        $source = $this->source();
        $this->sync($source);
        $this->assertSame([['field' => 'phone', 'candidates' => ['phone', 'whatsapp']]], $source->refresh()->mapping_meta['ambiguous']);

        $this->actingAs($this->t['admin'])
            ->patchJson("/api/v1/integrations/sheets/{$source->uuid}/mapping", ['overrides' => ['phone' => 'whatsapp']])
            ->assertOk()
            ->assertJsonPath('data.source.mapping.phone', 'whatsapp')
            ->assertJsonPath('data.source.ambiguous', []);

        $this->headers[] = 'notes';
        $this->dataRows = [];
        $this->sync($source);

        $this->assertSame('whatsapp', $source->refresh()->mapping['phone']);
    }

    public function test_connecting_a_sheet_needs_no_mapping_at_all(): void
    {
        config(['integrations.google_sheets.client_id' => 'c', 'integrations.google_sheets.client_secret' => 's']);
        Queue::fake();

        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/integrations/sheets', ['spreadsheet_id' => 'meta-sheet', 'sheet_name' => 'Leads'])
            ->assertCreated()
            ->assertJsonPath('data.source.mapping.phone', 'phone_number')
            ->assertJsonPath('data.source.mapping.campaign_name', 'campaign_name')
            ->assertJsonPath('data.source.confidence.phone', 1);

        Queue::assertPushed(SyncSheetSource::class);
    }

    public function test_the_sheets_own_ping_queues_its_sync_and_nothing_else(): void
    {
        Queue::fake();
        $source = $this->source();
        $token = $source->push_token;

        $this->postJson("/api/v1/integrations/sheets/{$source->uuid}/ping/wrong-token")->assertNotFound();
        Queue::assertNothingPushed();

        $this->postJson("/api/v1/integrations/sheets/{$source->uuid}/ping/{$token}")->assertOk();
        Queue::assertPushed(SyncSheetSource::class, 1);
        $this->assertNotNull($source->refresh()->last_push_at);

        // The secret never leaves the server in a listing — only inside the URL
        // an admin copies into the sheet.
        $this->actingAs($this->t['admin'])->getJson('/api/v1/integrations/sheets')
            ->assertJsonMissingPath('data.sources.0.push_token')
            ->assertJsonPath('data.sources.0.ping_url', url("/api/v1/integrations/sheets/{$source->uuid}/ping/{$token}"));
    }

    public function test_custom_fields_from_one_workspace_never_appear_in_another(): void
    {
        $this->dataRows = [$this->row(['full_name' => 'ج', 'phone_number' => '+966550000004', 'ما_نوع_الوحدة_المطلوبة؟' => 'شقة'])];
        $this->sync($this->source());

        $other = $this->provisionTenant('Other Co');

        $this->assertSame(0, $this->asTenant($other['tenant'], fn () => CustomField::count()));
        $this->assertGreaterThan(0, $this->asTenant($this->t['tenant'], fn () => CustomField::count()));
    }
}
