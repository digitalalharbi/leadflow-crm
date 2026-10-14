<?php

namespace App\Domain\Sheets;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reading a Google Sheet, and nothing else.
 *
 * Deliberately read-only — there is no method here that writes. A product that
 * can edit a customer's spreadsheet is one that will eventually edit it by
 * accident, and the scope requested from Google says so too:
 * `spreadsheets.readonly` and `drive.metadata.readonly`.
 *
 * Every method answers with a reason rather than throwing. A sheet somebody
 * renamed, a tab somebody deleted and an expired grant are all ordinary states
 * the screen has to explain, not exceptions for a job to swallow.
 */
class SheetReader
{
    public function __construct(private readonly GoogleTokens $tokens) {}

    /**
     * The spreadsheets this connection may see, newest first.
     *
     * Through Drive rather than Sheets, because Sheets has no "list" — and only
     * the metadata scope, which can read a file's name and not a cell of it.
     *
     * @return array{items:array<int,array{id:string,name:string,modified_at:?string}>,error:?string}
     */
    public function spreadsheets(Integration $connection, ?string $search = null): array
    {
        $query = "mimeType='application/vnd.google-apps.spreadsheet' and trashed=false";

        if (filled($search)) {
            // Escaped: a name with an apostrophe would otherwise end the clause
            // and change the query into something else entirely.
            $query .= " and name contains '".str_replace("'", "\\'", (string) $search)."'";
        }

        $result = $this->get($connection, 'https://www.googleapis.com/drive/v3/files', [
            'q' => $query,
            'orderBy' => 'modifiedTime desc',
            'pageSize' => 50,
            'fields' => 'files(id,name,modifiedTime)',
        ]);

        if ($result['error'] !== null) {
            return ['items' => [], 'error' => $result['error']];
        }

        return [
            'items' => array_map(fn (array $file) => [
                'id' => $file['id'] ?? '',
                'name' => $file['name'] ?? '',
                'modified_at' => $file['modifiedTime'] ?? null,
            ], $result['json']['files'] ?? []),
            'error' => null,
        ];
    }

    /**
     * The tabs in one spreadsheet.
     *
     * A spreadsheet with a "Meta" tab and a "TikTok" tab is two sources, and
     * mapping them separately is the point — so the tab is chosen, never
     * assumed to be the first one.
     *
     * @return array{items:array<int,string>,name:?string,error:?string}
     */
    public function tabs(Integration $connection, string $spreadsheetId): array
    {
        $result = $this->get($connection, "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}", [
            // Titles only: without this, Google returns every cell of every tab.
            'fields' => 'properties.title,sheets.properties.title',
        ]);

        if ($result['error'] !== null) {
            return ['items' => [], 'name' => null, 'error' => $result['error']];
        }

        return [
            'items' => array_values(array_filter(array_map(
                fn (array $sheet) => $sheet['properties']['title'] ?? null,
                $result['json']['sheets'] ?? [],
            ))),
            'name' => $result['json']['properties']['title'] ?? null,
            'error' => null,
        ];
    }

    /**
     * Rows from a tab, as a list of lists.
     *
     * Google omits trailing empty cells rather than padding them, so a row
     * whose last three columns are blank comes back short. Callers pad against
     * the header; a reader that guessed would be guessing about somebody's data.
     *
     * @return array{rows:array<int,array<int,string>>,error:?string}
     */
    public function rows(Integration $connection, string $spreadsheetId, string $sheet, int $fromRow, int $limit): array
    {
        $range = sprintf('%s!A%d:Z%d', $sheet, $fromRow, $fromRow + $limit - 1);

        $result = $this->get(
            $connection,
            "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/".rawurlencode($range),
            ['majorDimension' => 'ROWS'],
        );

        if ($result['error'] !== null) {
            return ['rows' => [], 'error' => $result['error']];
        }

        return ['rows' => $result['json']['values'] ?? [], 'error' => null];
    }

    /** The header row, which is what a mapping screen offers as column names. */
    public function headers(Integration $connection, string $spreadsheetId, string $sheet, int $headerRow): array
    {
        $result = $this->rows($connection, $spreadsheetId, $sheet, $headerRow, 1);

        return [
            'headers' => array_map(fn ($value) => trim((string) $value), $result['rows'][0] ?? []),
            'error' => $result['error'],
        ];
    }

    /** @return array{json:array|null,error:?string} */
    private function get(Integration $connection, string $url, array $query): array
    {
        $token = $this->tokens->accessToken($connection);

        if (blank($token)) {
            // The connection already carries the reason; repeating it here as
            // "unreachable" would hide "this grant was revoked" behind it.
            return ['json' => null, 'error' => $connection->last_error ?? 'not_connected'];
        }

        try {
            $response = Http::withToken($token)->timeout(30)->retry(2, 500, throw: false)->get($url, $query);
        } catch (\Throwable $e) {
            Log::warning('google_sheets.transport_failed', [
                'integration' => $connection->uuid,
                'exception' => $e::class,
            ]);

            return ['json' => null, 'error' => 'unreachable'];
        }

        if ($response->successful()) {
            return ['json' => $response->json(), 'error' => null];
        }

        return ['json' => null, 'error' => match ($response->status()) {
            401 => 'reauth_required',
            403 => 'forbidden',
            // A sheet somebody renamed or a tab somebody deleted. Ordinary, and
            // the only fix is a person choosing again.
            404 => 'not_found',
            429 => 'rate_limited',
            default => $response->status() >= 500 ? 'unreachable' : 'request_failed',
        }];
    }
}
