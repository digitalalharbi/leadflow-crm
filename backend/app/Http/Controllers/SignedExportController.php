<?php

namespace App\Http\Controllers;

use App\Models\DataExport;
use App\Support\TenantContext;
use App\Support\TenantStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Opens a finished export through the five-minute signed link its owner asked for. */
class SignedExportController extends Controller
{
    private const TYPES = [
        'csv' => 'text/csv; charset=utf-8',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip' => 'application/zip',
    ];

    public function __invoke(Request $request, string $export, TenantContext $context): StreamedResponse
    {
        $tenantId = (int) $request->query('tenant');
        $row = $context->withoutScope(fn () => DataExport::where('uuid', $export)->where('tenant_id', $tenantId)->first());

        abort_if($row === null || ! $row->isDownloadable() || ! TenantStorage::owns($tenantId, (string) $row->path), 404);
        abort_unless(Storage::disk('local')->exists($row->path), 404);

        return Storage::disk('local')->download($row->path, $row->filename ?? basename($row->path), [
            'Content-Type' => self::TYPES[$row->format] ?? 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
