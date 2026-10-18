<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Lets the backup script tell the application it ran.
 *
 * The backup is taken on the host, from outside the containers, so the
 * application cannot see the file it is supposed to vouch for. What it can read
 * is a note left on the storage volume — written by the script *after* the dump
 * has been read back and, where configured, copied off the server. A readiness
 * screen that said "backups" because a cron line existed would be the
 * comfortable lie; this says "a backup was verified readable at 02:00".
 */
class RecordBackup extends Command
{
    protected $signature = 'leadflow:record-backup
        {--size= : Human-readable size of the dump}
        {--offsite : The copy off the server succeeded}
        {--failed= : The reason, when the backup did not complete}';

    protected $description = 'Record the outcome of a database backup for the readiness screen.';

    public static function path(): string
    {
        return storage_path('app/backup-status.json');
    }

    /** @return array<string,mixed>|null */
    public static function read(): ?array
    {
        $path = self::path();

        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    public function handle(): int
    {
        $failed = $this->option('failed');

        $status = [
            'at' => now()->toIso8601String(),
            'ok' => $failed === null,
            'size' => $this->option('size'),
            'offsite' => (bool) $this->option('offsite'),
            'error' => $failed !== null ? mb_substr((string) $failed, 0, 240) : null,
        ];

        // The last good backup is kept when a later one fails: "it failed last
        // night" and "when was the last one that worked" are two questions.
        if ($failed !== null) {
            $previous = self::read();
            $status['last_good_at'] = $previous['ok'] ?? false ? $previous['at'] : ($previous['last_good_at'] ?? null);
        }

        file_put_contents(self::path(), json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
