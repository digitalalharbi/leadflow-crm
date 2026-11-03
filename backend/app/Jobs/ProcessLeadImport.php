<?php

namespace App\Jobs;

use App\DataTransferObjects\IncomingLead;
use App\DataTransferObjects\IngestionResult;
use App\Domain\Exports\ActingScope;
use App\Enums\IntegrationProvider;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Leads\LeadIngestionService;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessLeadImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $importId, public int $tenantId) {}

    /**
     * An import that crashed has to say so, and must not keep the file.
     *
     * `$tries = 1`, so there is no retry to preserve the upload for: without
     * this, a job that threw anywhere outside the per-row `try` left the import
     * showing "جارٍ المعالجة" for the rest of the workspace's life, and left the
     * customer's CSV — names, numbers, every row of it — sitting in storage.
     * The success path deletes it and says why; the failure path did neither.
     *
     * No tenant context here: `failed()` runs after the job has been torn down,
     * and the scope fails closed outside a request, so a scoped lookup would
     * find nothing and silently do none of this.
     */
    public function failed(\Throwable $exception): void
    {
        $import = Import::withoutGlobalScopes()->find($this->importId);

        if (! $import) {
            return;
        }

        $reference = (string) Str::uuid();

        Log::error('import.failed', [
            'import' => $import->uuid,
            'reference' => $reference,
            // The class and a reference, never the message: an exception from a
            // parser can repeat the row that broke it, and that row is a
            // customer's name and phone number.
            'exception' => $exception::class,
            'at' => $exception->getFile().':'.$exception->getLine(),
        ]);

        $import->update([
            'status' => Import::FAILED,
            'error' => 'تعذّرت معالجة الملف. الرقم المرجعي: '.$reference,
        ]);

        Storage::delete($import->getRawOriginal('path'));
    }

    public function handle(LeadIngestionService $ingestion, TenantContext $context): void
    {
        $tenant = $context->withoutScope(fn () => Tenant::find($this->tenantId));

        if (! $tenant) {
            return;
        }

        // Everything a person's file does, it does within what that person may touch.
        $context->withTenant($tenant, function () use ($tenant, $ingestion) {
            $uploader = User::query()->find(Import::query()->whereKey($this->importId)->value('user_id'));
            $work = fn () => $this->importRows($tenant, $ingestion);

            $uploader ? app(ActingScope::class)->run($uploader, null, $work) : $work();
        });
    }

    private function importRows(Tenant $tenant, LeadIngestionService $ingestion): void
    {
        $import = Import::find($this->importId);

        if (! $import) {
            return;
        }

        $path = $import->getRawOriginal('path');

        if (! Storage::exists($path)) {
            $import->update(['status' => Import::FAILED, 'error' => 'Uploaded file is no longer available.']);

            return;
        }

        $handle = fopen(Storage::path($path), 'r');
        $headers = fgetcsv($handle) ?: [];

        if (isset($headers[0])) {
            $headers[0] = preg_replace('/^\x{FEFF}/u', '', $headers[0]);
        }

        $mapping = $import->mapping ?? [];
        $defaults = $import->defaults ?? [];
        $imported = $duplicates = $failed = 0;
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            $raw = array_combine(
                $headers,
                array_pad(array_slice($row, 0, count($headers)), count($headers), null)
            );

            // Apply the user's column mapping; ignore unmapped columns.
            $mapped = $defaults;

            foreach ($mapping as $header => $target) {
                if (array_key_exists($header, $raw) && $raw[$header] !== null && $raw[$header] !== '') {
                    $mapped[$target] = trim((string) $raw[$header]);
                }
            }

            try {
                $result = $ingestion->ingest(
                    $tenant,
                    IncomingLead::fromArray(IntegrationProvider::CsvImport, $mapped, $raw),
                );

                $status = match ($result->outcome) {
                    IngestionResult::CREATED => 'imported',
                    IngestionResult::RE_ENGAGED => 'duplicate',
                    default => 'failed',
                };

                match ($status) {
                    'imported' => $imported++,
                    'duplicate' => $duplicates++,
                    default => $failed++,
                };

                ImportRow::create([
                    'import_id' => $import->id,
                    'lead_id' => $result->lead?->id,
                    'row_number' => $rowNumber,
                    'status' => $status,
                    'data' => $status === 'failed' ? $raw : null,
                    'error' => $result->reason,
                ]);
            } catch (\Throwable $e) {
                $failed++;

                ImportRow::create([
                    'import_id' => $import->id,
                    'row_number' => $rowNumber,
                    'status' => 'failed',
                    'data' => $raw,
                    'error' => substr($e->getMessage(), 0, 500),
                ]);

                Log::warning('import.row_failed', ['import' => $import->uuid, 'row' => $rowNumber]);
            }
        }

        fclose($handle);

        $import->update([
            'status' => Import::COMPLETED,
            'imported_rows' => $imported,
            'duplicate_rows' => $duplicates,
            'failed_rows' => $failed,
            'total_rows' => $rowNumber - 1,
            'completed_at' => now(),
        ]);

        // The uploaded file has served its purpose; do not keep customer PII around.
        Storage::delete($path);
    }
}
