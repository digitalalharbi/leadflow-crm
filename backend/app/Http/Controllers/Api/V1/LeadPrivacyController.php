<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Privacy\PersonalData;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A person's right to see what the company holds about them, and to have it erased (PDPL / GDPR style requests).
 */
class LeadPrivacyController extends Controller
{
    public function __construct(private PersonalData $data, private AuditLogger $audit) {}

    public function export(Request $request, string $uuid): StreamedResponse
    {
        $lead = Lead::where('uuid', $uuid)->firstOrFail();
        $this->authorize('managePersonalData', $lead);

        $payload = $this->data->export($lead);
        $this->audit->log('data.exported', $request->user(), $lead, ['kind' => 'personal_data']);

        return response()->streamDownload(
            fn () => print (json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'personal-data-'.$lead->uuid.'.json',
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'private, no-store'],
        );
    }

    /**
     * Irreversible, so the request must carry the lead's current name back — a mistyped id or a double click does
     * not erase somebody.
     */
    public function erase(Request $request, string $uuid)
    {
        $lead = Lead::where('uuid', $uuid)->firstOrFail();
        $this->authorize('managePersonalData', $lead);

        $data = $request->validate([
            'confirm' => ['required', 'string', 'max:255'],
            'suppress' => ['sometimes', 'boolean'],
        ]);

        if (trim($data['confirm']) !== trim((string) $lead->full_name)) {
            return ApiResponse::error('The name does not match.', ['confirm' => ['Type the lead’s name exactly to confirm.']], 422);
        }

        $result = $this->data->erase($lead, $request->user(), (bool) ($data['suppress'] ?? true));

        return ApiResponse::success(['erased' => true] + $result);
    }
}
