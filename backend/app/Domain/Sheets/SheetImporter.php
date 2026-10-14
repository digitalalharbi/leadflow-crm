<?php

namespace App\Domain\Sheets;

use App\DataTransferObjects\IncomingLead;
use App\DataTransferObjects\IngestionResult;
use App\Enums\IntegrationProvider;
use App\Models\Project;
use App\Models\SheetSource;
use App\Models\SheetSyncRun;
use App\Models\Tenant;
use App\Services\Leads\LeadIngestionService;
use Illuminate\Support\Facades\Log;

/**
 * Turning rows into leads, through the same door as everything else.
 *
 * Every row goes to `LeadIngestionService` — the same call a Meta webhook
 * makes. That is the whole design: a lead from a sheet is deduplicated,
 * attributed, scored, assigned and followed up exactly like a lead from an ad,
 * because a second pipeline would be a second set of rules to keep in step and
 * the first thing to drift would be deduplication.
 *
 * Two kinds of failure, kept apart, because they need opposite handling:
 *
 *   - **A row that can never work** — no phone, an unreadable number, a name
 *     where a number should be. Recorded, counted, and the cursor moves past
 *     it. Otherwise every sync for the rest of the year stops at row 41.
 *   - **A failure that is ours** — the API, the database, an expired grant.
 *     The run stops where it is and the cursor stays, so the next sync picks
 *     up the rows nobody has read yet.
 */
class SheetImporter
{
    /** Rows per request. Google allows far more; a smaller batch fails smaller. */
    private const BATCH = 200;

    /** A ceiling per run, so a first import of 40,000 rows cannot run for an hour. */
    private const MAX_ROWS_PER_RUN = 2_000;

    public function __construct(
        private readonly SheetReader $reader,
        private readonly LeadIngestionService $ingestion,
    ) {}

    public function sync(SheetSource $source, Tenant $tenant, string $trigger = 'scheduled'): SheetSyncRun
    {
        /*
         * The column has a database default, which does not reach a model that
         * was created without naming it — so a source that has never synced
         * arrives here with a null cursor and would insert one.
         */
        $startedAt = (int) ($source->cursor_row ?? 0);

        $run = SheetSyncRun::create([
            'sheet_source_id' => $source->id,
            'trigger' => $trigger,
            'status' => SheetSyncRun::RUNNING,
            'cursor_before' => $startedAt,
            'cursor_after' => $startedAt,
            'started_at' => now(),
        ]);

        $connection = $source->connection;

        if (! $connection) {
            return $this->finish($run, $source, 'not_connected');
        }

        $headers = $this->reader->headers($connection, $source->spreadsheet_id, $source->sheet_name, $source->header_row);

        if ($headers['error'] !== null) {
            return $this->finish($run, $source, $headers['error']);
        }

        if ($headers['headers'] === []) {
            // A tab with no header row cannot be mapped onto anything, and
            // importing it positionally would invent meanings for columns.
            return $this->finish($run, $source, 'no_headers');
        }

        $columns = array_flip($headers['headers']);
        $cursor = $startedAt;
        $failures = [];
        $counts = ['seen' => 0, 'imported' => 0, 'duplicates' => 0, 'failed' => 0];

        while ($counts['seen'] < self::MAX_ROWS_PER_RUN) {
            $from = max($cursor, (int) ($source->header_row ?? 1)) + 1;
            $batch = $this->reader->rows($connection, $source->spreadsheet_id, $source->sheet_name, $from, self::BATCH);

            if ($batch['error'] !== null) {
                // Ours, not the sheet's: stop here and keep the cursor, so the
                // rows nobody has read yet are read next time.
                $this->apply($run, $source, $cursor, $counts, $failures);

                return $this->finish($run, $source, $batch['error']);
            }

            if ($batch['rows'] === []) {
                break;
            }

            foreach ($batch['rows'] as $offset => $row) {
                $rowNumber = $from + $offset;
                $counts['seen']++;

                /*
                 * A blank row in the middle of a sheet is normal — somebody
                 * left a gap — and is not a failure. The cursor still moves, so
                 * the gap is crossed rather than re-read forever.
                 */
                if ($this->isBlank($row)) {
                    $cursor = $rowNumber;

                    continue;
                }

                try {
                    $result = $this->importRow($source, $tenant, $columns, $row, $rowNumber);
                } catch (\Throwable $e) {
                    /*
                     * Ours. The row is not at fault, so it is left unread and
                     * the run ends here rather than marking a customer's lead
                     * as rejected because our database was busy.
                     */
                    Log::error('sheets.row_failed', [
                        'source' => $source->uuid,
                        'row' => $rowNumber,
                        'exception' => $e::class,
                    ]);

                    $this->apply($run, $source, $cursor, $counts, $failures);

                    return $this->finish($run, $source, 'import_failed');
                }

                $cursor = $rowNumber;

                match (true) {
                    $result->isDuplicate() => $counts['duplicates']++,
                    $result->wasAccepted() => $counts['imported']++,
                    default => $this->recordFailure($counts, $failures, $rowNumber, $result->reason ?? 'rejected'),
                };
            }

            if (count($batch['rows']) < self::BATCH) {
                break;
            }
        }

        $this->apply($run, $source, $cursor, $counts, $failures);

        return $this->finish($run, $source, null);
    }

    /**
     * One row, mapped and sent through the ordinary pipeline.
     *
     * The provider is recorded as Google Sheets so attribution says where the
     * lead came from, while the *platform* comes from the sheet when it says
     * one — a sheet of Meta leads somebody exported is still Meta's lead.
     */
    private function importRow(SheetSource $source, Tenant $tenant, array $columns, array $row, int $rowNumber): IngestionResult
    {
        $mapped = [];

        foreach ($source->mapping ?? [] as $field => $header) {
            if (! in_array($field, SheetSource::MAPPABLE, true) || blank($header)) {
                continue;
            }

            $index = $columns[$header] ?? null;

            // Google omits trailing empty cells rather than padding them, so a
            // row whose last columns are blank arrives short.
            $value = $index === null ? null : ($row[$index] ?? null);

            if (filled($value)) {
                $mapped[$field] = trim((string) $value);
            }
        }

        $defaults = $source->defaults ?? [];

        $mapped['platform'] = $mapped['platform'] ?? ($defaults['platform'] ?? null);
        $mapped['campaign_name'] = $mapped['campaign_name'] ?? ($defaults['campaign_name'] ?? null);

        /*
         * The sheet and the row identify the lead for idempotency: re-importing
         * a sheet after somebody widens the mapping must not create a second
         * copy of row 41, and the duplicate matcher looks at this first.
         */
        $incoming = IncomingLead::fromArray(
            IntegrationProvider::GoogleSheets,
            array_filter($mapped, fn ($value) => $value !== null) + [
                'provider_lead_id' => $source->spreadsheet_id.':'.$source->sheet_name.':'.$rowNumber,
                'project_id' => $this->projectFor($source, $mapped),
            ],
        );

        return $this->ingestion->ingest($tenant, $incoming);
    }

    /**
     * The project this lead belongs to.
     *
     * A name in the sheet wins over the source's default, because a sheet with
     * a project column is a sheet covering several projects — and silently
     * stamping them all with one would put every lead on the wrong team.
     */
    private function projectFor(SheetSource $source, array $mapped): ?int
    {
        $named = $mapped['project'] ?? null;

        if (filled($named)) {
            $project = Project::where('name', $named)->first();

            if ($project) {
                return $project->id;
            }
        }

        return $source->defaults['project_id'] ?? null;
    }

    private function isBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (filled($cell)) {
                return false;
            }
        }

        return true;
    }

    private function recordFailure(array &$counts, array &$failures, int $rowNumber, string $reason): void
    {
        $counts['failed']++;

        // Capped: enough to fix the sheet, not enough to become a copy of the
        // rows that were refused.
        if (count($failures) < SheetSyncRun::MAX_RECORDED_FAILURES) {
            $failures[] = ['row' => $rowNumber, 'reason' => $reason];
        }
    }

    private function apply(SheetSyncRun $run, SheetSource $source, int $cursor, array $counts, array $failures): void
    {
        $run->forceFill([
            'rows_seen' => $counts['seen'],
            'imported' => $counts['imported'],
            'duplicates' => $counts['duplicates'],
            'failed' => $counts['failed'],
            'cursor_after' => $cursor,
            'failures' => $failures ?: null,
        ])->save();

        $source->forceFill(['cursor_row' => $cursor])->save();
    }

    private function finish(SheetSyncRun $run, SheetSource $source, ?string $error): SheetSyncRun
    {
        $run->forceFill([
            'status' => $error === null ? SheetSyncRun::COMPLETED : SheetSyncRun::FAILED,
            'error' => $error,
            'finished_at' => now(),
        ])->save();

        $source->forceFill([
            'last_synced_at' => now(),
            'last_error' => $error,
        ])->save();

        return $run->refresh();
    }
}
