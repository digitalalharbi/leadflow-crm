<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Sheets\GoogleOAuth;
use App\Domain\Sheets\SheetReader;
use App\Enums\IntegrationProvider;
use App\Http\Controllers\Controller;
use App\Jobs\SyncSheetSource;
use App\Models\Integration;
use App\Models\SheetSource;
use App\Models\SheetSyncRun;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Connecting a sheet, mapping it, and watching it work.
 *
 * Nothing here needs a developer. A manager picks the spreadsheet, picks the
 * tab, says which column is the phone number, sees ten real rows mapped before
 * anything is imported, and presses save — after which the schedule does the
 * work and this screen only has to explain itself when something breaks.
 */
class SheetSourceController extends Controller
{
    public function __construct(
        private readonly SheetReader $reader,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Integration::class);

        $sources = SheetSource::with('connection')->orderBy('id')->get();

        return ApiResponse::success([
            'sources' => $sources->map(fn (SheetSource $source) => $this->present($source))->values()->all(),
            'connection' => $this->connectionState(),
            'mappable' => SheetSource::MAPPABLE,
        ]);
    }

    /** The tabs in one spreadsheet, and its name. */
    public function tabs(Request $request)
    {
        $this->authorize('viewAny', Integration::class);

        $data = $request->validate(['spreadsheet_id' => ['required', 'string', 'max:255']]);

        $result = $this->reader->tabs($this->requireConnection(), $data['spreadsheet_id']);

        if ($result['error'] !== null) {
            return $this->failure($result['error']);
        }

        return ApiResponse::success(['tabs' => $result['items'], 'name' => $result['name']]);
    }

    /**
     * What the first few rows would become.
     *
     * The step that stops a mapping mistake becoming four thousand leads with
     * the city in the name field. Nothing is imported here and no cursor moves
     * — it reads the sheet and shows the result of applying the mapping.
     */
    public function preview(Request $request)
    {
        $this->authorize('viewAny', Integration::class);

        $data = $request->validate([
            'spreadsheet_id' => ['required', 'string', 'max:255'],
            'sheet_name' => ['required', 'string', 'max:255'],
            'header_row' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'mapping' => ['sometimes', 'array'],
        ]);

        $connection = $this->requireConnection();
        $headerRow = (int) ($data['header_row'] ?? 1);

        $headers = $this->reader->headers($connection, $data['spreadsheet_id'], $data['sheet_name'], $headerRow);

        if ($headers['error'] !== null) {
            return $this->failure($headers['error']);
        }

        $rows = $this->reader->rows($connection, $data['spreadsheet_id'], $data['sheet_name'], $headerRow + 1, 10);

        if ($rows['error'] !== null) {
            return $this->failure($rows['error']);
        }

        $columns = array_flip($headers['headers']);
        $mapping = array_intersect_key($data['mapping'] ?? [], array_flip(SheetSource::MAPPABLE));

        $mapped = array_map(function (array $row) use ($columns, $mapping) {
            $out = [];

            foreach ($mapping as $field => $header) {
                $index = $columns[$header] ?? null;
                $out[$field] = $index === null ? null : ($row[$index] ?? null);
            }

            return $out;
        }, $rows['rows']);

        return ApiResponse::success([
            'headers' => $headers['headers'],
            'rows' => $rows['rows'],
            'mapped' => $mapped,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Integration::class);

        $data = $this->validateSource($request);
        $connection = $this->requireConnection();

        $source = SheetSource::updateOrCreate(
            [
                'spreadsheet_id' => $data['spreadsheet_id'],
                'sheet_name' => $data['sheet_name'],
            ],
            [
                'integration_id' => $connection->id,
                'spreadsheet_name' => $data['spreadsheet_name'] ?? null,
                'header_row' => $data['header_row'] ?? 1,
                'mapping' => $data['mapping'],
                'defaults' => $data['defaults'] ?? null,
                'is_active' => true,
            ],
        );

        $this->audit->log('sheets.connected', $request->user(), null, [
            'spreadsheet' => $source->spreadsheet_name ?? $source->spreadsheet_id,
            'sheet' => $source->sheet_name,
        ]);

        /*
         * The first import starts immediately rather than waiting a quarter of
         * an hour. Somebody who has just finished mapping a sheet is watching
         * to see whether it worked, and the answer "wait fifteen minutes" is
         * how a working integration gets reported as broken.
         */
        SyncSheetSource::dispatch($source->id, 'manual');

        return ApiResponse::created(['source' => $this->present($source->refresh())]);
    }

    public function update(Request $request, string $uuid)
    {
        $source = SheetSource::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $source->connection ?? new Integration);

        $data = $request->validate([
            'mapping' => ['sometimes', 'array'],
            'defaults' => ['sometimes', 'nullable', 'array'],
            'header_row' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
            /*
             * Re-reading from a chosen row, for the one case that needs it:
             * somebody widened the mapping and wants the rows already imported
             * to pick up the new columns. Deduplication is what makes that safe
             * — every row carries the same identity it did the first time, so
             * re-reading updates rather than duplicates.
             */
            'cursor_row' => ['sometimes', 'integer', 'min:0'],
        ]);

        $source->update($data);

        return ApiResponse::success(['source' => $this->present($source->refresh())]);
    }

    public function destroy(Request $request, string $uuid)
    {
        $source = SheetSource::where('uuid', $uuid)->firstOrFail();
        $this->authorize('delete', $source->connection ?? new Integration);

        $source->delete();
        $this->audit->log('sheets.disconnected', $request->user(), null, ['sheet' => $source->sheet_name]);

        return ApiResponse::success(null, 'Sheet disconnected.');
    }

    /** For the moment after a mapping is fixed. The schedule is the normal path. */
    public function sync(Request $request, string $uuid)
    {
        $source = SheetSource::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $source->connection ?? new Integration);

        SyncSheetSource::dispatch($source->id, 'manual');

        return ApiResponse::success(['queued' => true], 'Sync queued.');
    }

    private function validateSource(Request $request): array
    {
        $data = $request->validate([
            'spreadsheet_id' => ['required', 'string', 'max:255'],
            'spreadsheet_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sheet_name' => ['required', 'string', 'max:255'],
            'header_row' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:255'],
            'defaults' => ['sometimes', 'nullable', 'array'],
        ]);

        $data['mapping'] = array_intersect_key($data['mapping'], array_flip(SheetSource::MAPPABLE));

        /*
         * A sheet with neither a phone nor an email column cannot produce a
         * lead anybody can contact, and importing four thousand names with no
         * way to reach them is worse than refusing.
         */
        if (blank($data['mapping']['phone'] ?? null) && blank($data['mapping']['email'] ?? null)) {
            throw ValidationException::withMessages([
                'mapping' => ['sheets.needs_phone_or_email'],
            ]);
        }

        return $data;
    }

    private function requireConnection(): Integration
    {
        $connection = Integration::where('provider', IntegrationProvider::GoogleSheets->value)->first();

        if (! $connection || blank($connection->credentials['refresh_token'] ?? null)) {
            throw ValidationException::withMessages([
                'connection' => ['sheets.not_connected'],
            ]);
        }

        return $connection;
    }

    private function connectionState(): array
    {
        $connection = Integration::where('provider', IntegrationProvider::GoogleSheets->value)->first();

        return [
            'connected' => $connection !== null && filled($connection->credentials['refresh_token'] ?? null),
            'status' => $connection?->status ?? Integration::NOT_CONNECTED,
            'last_error' => $connection?->last_error,
            // Whether the platform itself can offer the connection at all.
            // Without an OAuth client nobody can connect, and that is ours to
            // fix rather than the customer's.
            'oauth_configured' => filled(config('integrations.google_sheets.client_id'))
                && filled(config('integrations.google_sheets.client_secret')),
            // Its own flag: an account can be connected and have no way to
            // choose a file, and "connect" and "pick" fail for different
            // reasons and are fixed by changing different settings.
            'picker_configured' => app(GoogleOAuth::class)->pickerConfigured(),
        ];
    }

    private function reasonFor(string $error): string
    {
        return 'sheets.'.$error;
    }

    /**
     * A sheet read that did not work, as something the screen can show.
     *
     * With `drive.file`, Google answers 403 or 404 for a spreadsheet the person
     * did not pick in its picker — which is not an error to apologise for, it is
     * the grant working as intended. Said as such, because "not found" sends
     * somebody to check a link that is fine.
     *
     * Returned in `errors` as well as in the message: the interface shows the
     * first validation entry and ignores the message on a 422, so a reason that
     * lives only in the message is seen as "validation failed".
     */
    private function failure(string $error)
    {
        $key = in_array($error, ['forbidden', 'not_found'], true)
            ? 'sheets.not_picked'
            : $this->reasonFor($error);

        return ApiResponse::error($key, ['sheet' => [$key]], 422);
    }

    private function present(SheetSource $source): array
    {
        $last = SheetSyncRun::where('sheet_source_id', $source->id)->latest('started_at')->first();

        return [
            'id' => $source->uuid,
            'spreadsheet_id' => $source->spreadsheet_id,
            'spreadsheet_name' => $source->spreadsheet_name,
            'sheet_name' => $source->sheet_name,
            'header_row' => $source->header_row,
            'mapping' => $source->mapping,
            'defaults' => $source->defaults,
            'is_active' => $source->is_active,
            'health' => $source->health(),
            'last_run' => $last === null ? null : [
                'status' => $last->status,
                'trigger' => $last->trigger,
                'rows_seen' => $last->rows_seen,
                'imported' => $last->imported,
                'duplicates' => $last->duplicates,
                'failed' => $last->failed,
                'failures' => $last->failures,
                'error' => $last->error,
                'started_at' => $last->started_at?->toIso8601String(),
                'finished_at' => $last->finished_at?->toIso8601String(),
            ],
        ];
    }
}
