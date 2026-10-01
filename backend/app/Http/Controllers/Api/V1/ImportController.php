<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessLeadImport;
use App\Models\Import;
use App\Models\Lead;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * CSV import wizard: upload -> preview -> map columns -> validate -> import.
 * The actual import runs on the queue so a 10k-row file never blocks a request.
 */
class ImportController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('create', Lead::class);

        $imports = Import::with('user:id,uuid,name')->latest()->limit(25)->get();

        return ApiResponse::success([
            'imports' => $imports->map(fn ($i) => $this->present($i)),
        ]);
    }

    /** Step 1 + 2: store the file and return headers with a few sample rows. */
    public function upload(Request $request)
    {
        $this->authorize('create', Lead::class);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'], // 10 MB
        ]);

        $file = $request->file('file');
        $path = $file->store('imports/'.$request->user()->tenant_id);

        $handle = fopen($file->getRealPath(), 'r');
        $headers = fgetcsv($handle) ?: [];

        // Strip a UTF-8 BOM so the first column name maps correctly.
        if (isset($headers[0])) {
            $headers[0] = preg_replace('/^\x{FEFF}/u', '', $headers[0]);
        }

        $sample = [];
        $total = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $total++;
            if (count($sample) < 5) {
                $sample[] = array_combine(
                    $headers,
                    array_pad(array_slice($row, 0, count($headers)), count($headers), null)
                );
            }
        }

        fclose($handle);

        $import = Import::create([
            'user_id' => $request->user()->id,
            'original_filename' => $file->getClientOriginalName(),
            'path' => $path,
            'status' => Import::MAPPING,
            'headers' => $headers,
            'total_rows' => $total,
        ]);

        return ApiResponse::created([
            'import' => $this->present($import),
            'headers' => $headers,
            'sample' => $sample,
            'suggested_mapping' => $this->suggestMapping($headers),
            'target_fields' => $this->targetFields(),
        ]);
    }

    /** Step 3 + 5: save the mapping and queue the import. */
    public function process(Request $request, string $uuid)
    {
        $import = Import::where('uuid', $uuid)->firstOrFail();
        $this->authorize('create', Lead::class);

        if ($import->status === Import::PROCESSING || $import->status === Import::COMPLETED) {
            return ApiResponse::error('This import has already been processed.', null, 409);
        }

        $data = $request->validate([
            'mapping' => ['required', 'array', 'min:1'],
            'defaults' => ['nullable', 'array'],
        ]);

        $import->update([
            'mapping' => $data['mapping'],
            'defaults' => $data['defaults'] ?? null,
            'status' => Import::PROCESSING,
        ]);

        ProcessLeadImport::dispatch($import->id, $import->tenant_id);

        return ApiResponse::success(['import' => $this->present($import->fresh())], 'Import started.');
    }

    public function show(string $uuid)
    {
        $import = Import::with('user:id,uuid,name')->where('uuid', $uuid)->firstOrFail();
        $this->authorize('create', Lead::class);

        return ApiResponse::success([
            'import' => $this->present($import),
            'failed_rows' => $import->rows()->where('status', 'failed')->limit(50)->get()
                ->map(fn ($r) => ['row' => $r->row_number, 'error' => $r->error, 'data' => $r->data]),
        ]);
    }

    private function present(Import $import): array
    {
        return [
            'id' => $import->uuid,
            'filename' => $import->original_filename,
            'status' => $import->status,
            'headers' => $import->headers,
            'mapping' => $import->mapping,
            'total_rows' => $import->total_rows,
            'imported_rows' => $import->imported_rows,
            'duplicate_rows' => $import->duplicate_rows,
            'failed_rows' => $import->failed_rows,
            'error' => $import->error,
            'completed_at' => $import->completed_at?->toIso8601String(),
            'created_at' => $import->created_at?->toIso8601String(),
            'user' => $import->relationLoaded('user') && $import->user
                ? ['id' => $import->user->uuid, 'name' => $import->user->name] : null,
        ];
    }

    /** Best-effort guess so most users just click Continue. */
    private function suggestMapping(array $headers): array
    {
        $aliases = [
            'name' => 'full_name', 'full name' => 'full_name', 'full_name' => 'full_name',
            'الاسم' => 'full_name', 'اسم العميل' => 'full_name',
            'phone' => 'phone', 'mobile' => 'phone', 'جوال' => 'phone', 'الجوال' => 'phone',
            'رقم الجوال' => 'phone', 'phone number' => 'phone',
            'email' => 'email', 'البريد' => 'email', 'البريد الالكتروني' => 'email',
            'city' => 'city', 'المدينة' => 'city',
            'campaign' => 'campaign_name', 'campaign name' => 'campaign_name', 'الحملة' => 'campaign_name',
            'platform' => 'platform', 'المنصة' => 'platform',
            'source' => 'source', 'المصدر' => 'source',
            'budget' => 'budget_max', 'الميزانية' => 'budget_max',
        ];

        $mapping = [];

        foreach ($headers as $header) {
            $key = mb_strtolower(trim((string) $header));

            if (isset($aliases[$key])) {
                $mapping[$header] = $aliases[$key];
            }
        }

        return $mapping;
    }

    private function targetFields(): array
    {
        return [
            'full_name', 'first_name', 'last_name', 'phone', 'email', 'city',
            'platform', 'source', 'campaign_name', 'adset_name', 'ad_name',
            'budget_min', 'budget_max', 'preferred_property_type',
            'preferred_location', 'purchase_timeline', 'payment_method',
        ];
    }
}
