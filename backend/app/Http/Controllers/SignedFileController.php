<?php

namespace App\Http\Controllers;

use App\Models\ProjectFile;
use App\Support\TenantContext;
use App\Support\TenantStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves one file to a signed, expiring link (see ProjectFileController::link). The signature covers the file and its
 * company; the row is re-read and must still belong to that company and still be active, and its stored path must sit
 * under that company's prefix.
 */
class SignedFileController extends Controller
{
    public function __invoke(Request $request, string $file, TenantContext $context): StreamedResponse
    {
        $tenantId = (int) $request->query('tenant');

        $row = $context->withoutScope(fn () => ProjectFile::where('uuid', $file)->where('tenant_id', $tenantId)->first());

        abort_if($row === null || ! $row->is_active || ! TenantStorage::owns($tenantId, $row->path), 404);
        abort_unless(Storage::disk('local')->exists($row->path), 404);

        return Storage::disk('local')->download($row->path, $row->name, [
            'Content-Disposition' => 'inline; filename="'.addslashes($row->name).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
