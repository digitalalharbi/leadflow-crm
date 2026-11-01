<?php

namespace App\Domain\Exports;

use App\Domain\Access\Permissions;
use App\Models\DataExport;
use App\Models\User;
use App\Services\Leads\LeadQueryBuilder;
use App\Support\CsvWriter;
use App\Support\TenantStorage;
use App\Support\XlsxWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Makes the file for one export and stores it on the private disk under the company's own folder.
 *
 * Leads: the filtered list the person was looking at, as CSV or XLSX. Company: everything the workspace holds that a
 * company would take with it — leads, history, notes, appointments, follow-ups, reservations, projects, team and
 * campaigns — one CSV each in a ZIP. Secrets (tokens, keys, password hashes, provider payloads) are never included.
 */
class ExportBuilder
{
    /** Table => columns, for the company archive. Every one is filtered by tenant. */
    public const COMPANY_TABLES = [
        'leads' => ['uuid', 'full_name', 'phone_normalized', 'email', 'city', 'platform', 'source', 'campaign_name', 'status', 'temperature', 'score', 'tags', 'created_at', 'won_at', 'lost_at', 'deal_value_minor'],
        'lead_activities' => ['lead_id', 'type', 'description', 'created_at'],
        'lead_notes' => ['lead_id', 'body', 'created_at'],
        'appointments' => ['lead_id', 'scheduled_at', 'duration_minutes', 'status', 'location', 'outcome'],
        'follow_ups' => ['lead_id', 'due_at', 'status', 'note', 'outcome', 'completed_at'],
        'reservations' => ['lead_id', 'status', 'amount_minor', 'reserved_at', 'expires_at', 'converted_at'],
        'projects' => ['uuid', 'name', 'developer_name', 'location', 'status', 'created_at'],
        'users' => ['uuid', 'name', 'email', 'role', 'created_at'],
        'campaigns' => ['uuid', 'name', 'platform', 'status', 'created_at'],
    ];

    public function __construct(private LeadQueryBuilder $queries, private ActingScope $scope) {}

    /** @return array{path:string, filename:string, rows:int, bytes:int} */
    public function build(DataExport $export, User $user): array
    {
        $dir = TenantStorage::dir($export->tenant_id, 'exports');
        Storage::disk('local')->makeDirectory($dir);

        $stamp = now()->format('Y-m-d-His');
        $filename = "{$export->kind}-{$stamp}.{$export->format}";
        $path = $dir.'/'.$export->uuid.'.'.$export->format;
        $absolute = Storage::disk('local')->path($path);

        $hidden = Permissions::hiddenData($user);

        $rows = match ($export->kind) {
            'leads' => $this->leads($export, $user, $absolute, $hidden),
            'company' => $this->company($export, $absolute, $hidden),
            default => throw new RuntimeException('Unknown export.'),
        };

        return ['path' => $path, 'filename' => $filename, 'rows' => $rows, 'bytes' => (int) filesize($absolute)];
    }

    private function leads(DataExport $export, User $user, string $absolute, array $hidden): int
    {
        $filters = (array) ($export->filters ?? []);
        $company = isset($filters['_company']) ? (string) $filters['_company'] : null;
        unset($filters['_company']);

        return $this->scope->run($user, $company, function () use ($filters, $user, $export, $absolute, $hidden) {
            $query = $this->queries->build(Request::create('/', 'GET', $filters), $user)
                ->with(['assignee:id,uuid,name', 'project:id,uuid,name']);

            $rtl = str_starts_with((string) ($user->locale ?? 'ar'), 'ar');

            if ($export->format === 'xlsx') {
                $sheet = new XlsxWriter($absolute, 'Leads', $rtl);
                $sheet->writeRow(LeadExportColumns::headings());
                $query->chunk(500, fn ($leads) => $leads->each(fn ($lead) => $sheet->writeRow(LeadExportColumns::row($lead, $hidden))));
                $sheet->close();

                return $sheet->rows();
            }

            $handle = fopen($absolute, 'w');
            $csv = CsvWriter::open($handle);
            $csv->writeRow(LeadExportColumns::headings());
            $count = 0;
            $query->chunk(500, function ($leads) use ($csv, &$count, $hidden) {
                foreach ($leads as $lead) {
                    $csv->writeRow(LeadExportColumns::row($lead, $hidden));
                    $count++;
                }
            });
            fclose($handle);

            return $count;
        });
    }

    private function company(DataExport $export, string $absolute, array $hidden): int
    {
        $zip = new ZipArchive;

        if ($zip->open($absolute, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot write the archive.');
        }

        $temps = [];
        $total = 0;
        $leadUuids = DB::table('leads')->where('tenant_id', $export->tenant_id)->pluck('uuid', 'id');

        // What the person's role keeps from them stays out of the archive too, column by column.
        $blank = array_merge(
            in_array('contacts', $hidden, true) ? ['leads.phone_normalized', 'leads.email'] : [],
            in_array('revenue', $hidden, true) ? ['leads.deal_value_minor', 'reservations.amount_minor'] : [],
        );

        foreach (self::COMPANY_TABLES as $table => $columns) {
            $tmp = tempnam(sys_get_temp_dir(), 'exp');
            $temps[] = $tmp;
            $handle = fopen($tmp, 'w');
            $csv = CsvWriter::open($handle);
            $csv->writeRow(array_map(fn ($c) => $c === 'lead_id' ? 'lead' : $c, $columns));

            $query = DB::table($table)->where('tenant_id', $export->tenant_id)->select(['id', ...$columns])->orderBy('id');

            if (in_array($table, ['leads', 'appointments', 'projects', 'users'], true)) {
                $query->whereNull('deleted_at');
            }

            $query->chunkById(1000, function ($rows) use ($csv, $columns, $leadUuids, $table, $blank, &$total) {
                foreach ($rows as $row) {
                    $csv->writeRow(array_map(fn ($c) => match (true) {
                        in_array("{$table}.{$c}", $blank, true) => null,
                        $c === 'lead_id' => $leadUuids[$row->lead_id] ?? null,
                        default => $row->{$c},
                    }, $columns));
                    $total++;
                }
            });

            fclose($handle);
            $zip->addFile($tmp, $table.'.csv');
        }

        $zip->addFromString('README.txt', "Mohtam company export\nCreated: ".now()->toIso8601String()."\nOne CSV per table, UTF-8. Lead references are the lead's id in leads.csv.\n");
        $zip->close();

        foreach ($temps as $tmp) {
            @unlink($tmp);
        }

        return $total;
    }
}
