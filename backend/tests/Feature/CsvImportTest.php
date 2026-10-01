<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CsvImportTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->t = $this->provisionTenant('Import Realty');
    }

    private function csv(string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('leads.csv', $contents);
    }

    public function test_uploading_returns_headers_a_sample_and_a_suggested_mapping(): void
    {
        $file = $this->csv("الاسم,الجوال,المدينة\nفهد العتيبي,0512345678,الرياض\nنورة الشمري,0512345679,جدة\n");

        $response = $this->actingAs($this->t['admin'])->post('/api/v1/imports', ['file' => $file]);

        $response->assertCreated()
            ->assertJsonPath('data.import.total_rows', 2)
            ->assertJsonPath('data.headers', ['الاسم', 'الجوال', 'المدينة']);

        // Arabic headers are recognised, so most users just press continue.
        $this->assertSame([
            'الاسم' => 'full_name',
            'الجوال' => 'phone',
            'المدينة' => 'city',
        ], $response->json('data.suggested_mapping'));
    }

    public function test_processing_imports_rows_and_reports_the_outcome(): void
    {
        $file = $this->csv("name,mobile\nفهد العتيبي,0512345678\nنورة الشمري,0512345679\n");

        $importId = $this->actingAs($this->t['admin'])
            ->post('/api/v1/imports', ['file' => $file])->json('data.import.id');

        $this->actingAs($this->t['admin'])->postJson("/api/v1/imports/{$importId}/process", [
            'mapping' => ['name' => 'full_name', 'mobile' => 'phone'],
        ])->assertOk();

        $import = Import::withoutGlobalScopes()->first();

        $this->assertSame(Import::COMPLETED, $import->status);
        $this->assertSame(2, $import->imported_rows);
        $this->assertSame(0, $import->failed_rows);

        $this->assertDatabaseHas('leads', ['phone_normalized' => '+966512345678']);
        $this->assertDatabaseHas('leads', ['phone_normalized' => '+966512345679']);
    }

    public function test_duplicates_inside_the_file_are_counted_not_created_twice(): void
    {
        $file = $this->csv("name,mobile\nفهد,0512345678\nفهد مرة أخرى,+966512345678\n");

        $importId = $this->actingAs($this->t['admin'])
            ->post('/api/v1/imports', ['file' => $file])->json('data.import.id');

        $this->actingAs($this->t['admin'])->postJson("/api/v1/imports/{$importId}/process", [
            'mapping' => ['name' => 'full_name', 'mobile' => 'phone'],
        ])->assertOk();

        $import = Import::withoutGlobalScopes()->first();

        $this->assertSame(1, $import->imported_rows);
        $this->assertSame(1, $import->duplicate_rows);
        $this->assertSame(1, Lead::withoutGlobalScopes()->count());
    }

    public function test_unusable_rows_are_reported_without_failing_the_whole_import(): void
    {
        $file = $this->csv("name,mobile\nGood One,0512345678\nNo Phone,\nBad Phone,12\n");

        $importId = $this->actingAs($this->t['admin'])
            ->post('/api/v1/imports', ['file' => $file])->json('data.import.id');

        $this->actingAs($this->t['admin'])->postJson("/api/v1/imports/{$importId}/process", [
            'mapping' => ['name' => 'full_name', 'mobile' => 'phone'],
        ])->assertOk();

        $import = Import::withoutGlobalScopes()->first();

        $this->assertSame(1, $import->imported_rows);
        $this->assertSame(2, $import->failed_rows);

        $failures = $this->actingAs($this->t['admin'])->getJson("/api/v1/imports/{$importId}")
            ->json('data.failed_rows');

        $this->assertCount(2, $failures);
        $this->assertNotEmpty($failures[0]['error']);
    }

    public function test_defaults_are_applied_to_every_imported_row(): void
    {
        $file = $this->csv("name,mobile\nفهد,0512345678\n");

        $importId = $this->actingAs($this->t['admin'])
            ->post('/api/v1/imports', ['file' => $file])->json('data.import.id');

        $this->actingAs($this->t['admin'])->postJson("/api/v1/imports/{$importId}/process", [
            'mapping' => ['name' => 'full_name', 'mobile' => 'phone'],
            'defaults' => ['platform' => 'website', 'campaign_name' => 'استيراد يناير'],
        ])->assertOk();

        $lead = Lead::withoutGlobalScopes()->first();

        $this->assertSame('website', $lead->platform);
        $this->assertSame('استيراد يناير', $lead->campaign_name);
    }

    public function test_the_uploaded_file_is_deleted_after_processing(): void
    {
        $file = $this->csv("name,mobile\nفهد,0512345678\n");

        $importId = $this->actingAs($this->t['admin'])
            ->post('/api/v1/imports', ['file' => $file])->json('data.import.id');

        $path = Import::withoutGlobalScopes()->first()->getRawOriginal('path');
        Storage::assertExists($path);

        $this->actingAs($this->t['admin'])->postJson("/api/v1/imports/{$importId}/process", [
            'mapping' => ['name' => 'full_name', 'mobile' => 'phone'],
        ])->assertOk();

        // Customer PII does not linger on disk once it is in the database.
        Storage::assertMissing($path);
    }

    public function test_an_import_cannot_be_processed_twice(): void
    {
        $file = $this->csv("name,mobile\nفهد,0512345678\n");

        $importId = $this->actingAs($this->t['admin'])
            ->post('/api/v1/imports', ['file' => $file])->json('data.import.id');

        $payload = ['mapping' => ['name' => 'full_name', 'mobile' => 'phone']];

        $this->actingAs($this->t['admin'])->postJson("/api/v1/imports/{$importId}/process", $payload)->assertOk();
        $this->actingAs($this->t['admin'])->postJson("/api/v1/imports/{$importId}/process", $payload)->assertStatus(409);
    }

    public function test_a_non_csv_upload_is_rejected(): void
    {
        $this->actingAs($this->t['admin'])
            ->post('/api/v1/imports', ['file' => UploadedFile::fake()->create('leads.exe', 10)])
            ->assertStatus(422);
    }
}
