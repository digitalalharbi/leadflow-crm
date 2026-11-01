<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\DataExport;
use App\Models\Lead;
use App\Support\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Exports run in the background, carry exactly what the person could see, open through a short signed link only
 * for the person who made them, and are deleted after a day.
 */
class BackgroundExportsTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->a = $this->provisionTenant('Alpha');
        $this->b = $this->provisionTenant('Beta');
    }

    private function lead(array $t, array $attributes = []): Lead
    {
        return $this->asTenant($t['tenant'], fn () => Lead::create($attributes + ['full_name' => 'L '.str()->random(5), 'phone' => '05'.random_int(10000000, 99999999)]));
    }

    private function export(array $body, $user = null): array
    {
        return $this->actingAs($user ?? $this->a['admin'])->postJson('/api/v1/exports', $body)->assertCreated()->json('data.export');
    }

    private function file(string $id): string
    {
        $row = DataExport::withoutGlobalScopes()->where('uuid', $id)->firstOrFail();

        return Storage::disk('local')->path($row->path);
    }

    public function test_a_filtered_lead_export_becomes_an_xlsx_with_only_those_leads(): void
    {
        $this->lead($this->a, ['full_name' => 'Hot One', 'temperature' => 'hot']);
        $this->lead($this->a, ['full_name' => 'Cold One', 'temperature' => 'cold']);
        $this->lead($this->b, ['full_name' => 'Other Company', 'temperature' => 'hot']);

        $export = $this->export(['kind' => 'leads', 'format' => 'xlsx', 'filters' => ['temperature' => ['hot'], 'evil' => 'x']]);

        $listed = $this->actingAs($this->a['admin'])->getJson('/api/v1/exports')->assertOk()->json('data.exports.0');
        $this->assertSame('ready', $listed['status']);
        $this->assertSame(1, $listed['rows']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->file($export['id'])) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('Hot One', $sheet);
        $this->assertStringNotContainsString('Cold One', $sheet);
        $this->assertStringNotContainsString('Other Company', $sheet);
        $this->assertStringContainsString('rightToLeft="1"', $sheet);
    }

    public function test_a_rep_exports_only_their_own_leads_and_only_with_the_right(): void
    {
        $this->actingAs($this->makeUser($this->a['tenant'], Role::Sales))->postJson('/api/v1/exports', ['kind' => 'leads', 'format' => 'csv'])->assertForbidden();
        $this->actingAs($this->makeUser($this->a['tenant'], Role::Manager))->postJson('/api/v1/exports', ['kind' => 'company', 'format' => 'zip'])->assertForbidden();
        $this->actingAs($this->a['admin'])->postJson('/api/v1/exports', ['kind' => 'leads', 'format' => 'zip'])->assertUnprocessable();
    }

    public function test_the_company_archive_holds_every_table_of_this_company_and_no_secrets(): void
    {
        $this->lead($this->a, ['full_name' => 'Alpha Lead', 'email' => 'alpha@lead.test']);
        $this->lead($this->b, ['full_name' => 'Beta Lead']);

        $export = $this->export(['kind' => 'company', 'format' => 'zip']);

        $zip = new ZipArchive;
        $zip->open($this->file($export['id']));
        $this->assertNotFalse($zip->getFromName('leads.csv'));
        $this->assertNotFalse($zip->getFromName('users.csv'));
        $leads = $zip->getFromName('leads.csv');
        $this->assertStringContainsString('Alpha Lead', $leads);
        $this->assertStringNotContainsString('Beta Lead', $leads);
        $users = $zip->getFromName('users.csv');
        $this->assertStringNotContainsString('password', $users);
        $this->assertStringNotContainsString('mfa_secret', $users);
    }

    public function test_the_link_opens_the_file_only_for_its_owner_and_only_while_it_lives(): void
    {
        $this->lead($this->a, ['full_name' => 'Linked']);
        $export = $this->export(['kind' => 'leads', 'format' => 'csv']);

        $url = $this->actingAs($this->a['admin'])->postJson("/api/v1/exports/{$export['id']}/link")->assertOk()->json('data.url');
        $this->assertStringContainsString('Linked', $this->get($url)->assertOk()->streamedContent());

        // Tampered or unsigned: refused.
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();

        // Another admin in the same company, and another company, cannot mint a link to it.
        $this->actingAs($this->makeUser($this->a['tenant'], Role::Admin))->postJson("/api/v1/exports/{$export['id']}/link")->assertNotFound();
        $this->actingAs($this->b['admin'])->postJson("/api/v1/exports/{$export['id']}/link")->assertNotFound();

        // After a day it is gone, file and all.
        $this->travel(DataExport::KEEP_HOURS + 1)->hours();
        $this->artisan('leadflow:prune-exports')->assertSuccessful();
        $this->actingAs($this->a['admin'])->postJson("/api/v1/exports/{$export['id']}/link")->assertStatus(410);
        $this->assertSame([], Storage::disk('local')->allFiles('tenant/'.$this->a['tenant']->id.'/exports'));
    }

    public function test_the_xlsx_writer_escapes_text_and_never_writes_formulas(): void
    {
        $path = tempnam(sys_get_temp_dir(), 't').'.xlsx';
        $sheet = new XlsxWriter($path, 'T');
        $sheet->writeRow(['a', 'b']);
        $sheet->writeRow(['=HYPERLINK("http://evil")', '<b>&"', 42, "bad\x01char"]);
        $sheet->close();

        $zip = new ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringNotContainsString('<f>', $xml);
        $this->assertStringContainsString('&lt;b&gt;&amp;&quot;', $xml);
        $this->assertStringContainsString('<v>42</v>', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
        $this->assertSame('AA', XlsxWriter::column(26));
        @unlink($path);
    }
}
