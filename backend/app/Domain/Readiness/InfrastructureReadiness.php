<?php

namespace App\Domain\Readiness;

use App\Console\Commands\RecordBackup;
use App\Jobs\QueueHeartbeat;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Horizon;
use Sentry\Laravel\ServiceProvider;
use Throwable;

/**
 * What the platform itself stands on, checked rather than configured.
 *
 * Seven things a deployment depends on and nobody sees until they fail: the
 * database, Redis, the queue, Horizon, backups, logs and error tracking. Each is
 * answered with the same four facts — where it stands, when that was last
 * checked, the last thing that went wrong, and what a person has to do next.
 *
 * Three states, and the line between the last two is the point:
 *
 *   not_configured  it is missing, or it was tried and does not work
 *   ready           everything it needs has been supplied; nothing has been
 *                   seen working yet
 *   live_verified   it was *observed working*: a query that answered, a job a
 *                   worker ran, a backup that was read back
 *
 * A configuration value is never evidence. `QUEUE_CONNECTION=redis` is a
 * statement about intentions; a heartbeat job that ran is a statement about
 * the world.
 */
class InfrastructureReadiness
{
    public const NOT_CONFIGURED = 'not_configured';

    public const READY = 'ready';

    public const LIVE = 'live_verified';

    /** A backup older than this has missed a night. */
    private const BACKUP_FRESH_HOURS = 26;

    /** A pulse older than this is no pulse; the scheduler runs every minute. */
    private const PULSE_SECONDS = 180;

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return [
            $this->database(),
            $this->redis(),
            $this->queues(),
            $this->horizon(),
            $this->backups(),
            $this->logs(),
            $this->errorTracking(),
        ];
    }

    // ------------------------------------------------------------- database

    public function database(): array
    {
        $started = microtime(true);

        try {
            DB::select('select 1');
            $latency = (int) round((microtime(true) - $started) * 1000);
            $pending = $this->pendingMigrations();
        } catch (Throwable $e) {
            return $this->row('database', self::NOT_CONFIGURED, 'database_unreachable', $this->short($e), [
                'driver' => config('database.default'),
            ]);
        }

        return $this->row(
            'database',
            // It answered, which is the observation — but a schema that is behind
            // the code is a deployment half done, and "live" would be a lie.
            $pending === 0 ? self::LIVE : self::READY,
            $pending === 0 ? null : 'run_migrations',
            null,
            ['driver' => config('database.default'), 'latency_ms' => $latency, 'pending_migrations' => $pending],
        );
    }

    private function pendingMigrations(): int
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');

        $files = array_keys($migrator->getMigrationFiles($migrator->paths() + [database_path('migrations')]));
        $ran = $migrator->getRepository()->getRan();

        return count(array_diff($files, $ran));
    }

    // ---------------------------------------------------------------- redis

    public function redis(): array
    {
        $uses = array_filter([
            'queue' => config('queue.default') === 'redis',
            'cache' => config('cache.default') === 'redis',
            'session' => config('session.driver') === 'redis',
        ]);

        if ($uses === []) {
            return $this->row('redis', self::NOT_CONFIGURED, 'use_redis', null, [
                'used_for' => [],
            ]);
        }

        try {
            Redis::connection()->ping();
            $info = Redis::connection()->info();
        } catch (Throwable $e) {
            return $this->row('redis', self::NOT_CONFIGURED, 'redis_unreachable', $this->short($e), [
                'used_for' => array_keys($uses),
            ]);
        }

        return $this->row('redis', self::LIVE, null, null, [
            'used_for' => array_keys($uses),
            'memory' => $info['Memory']['used_memory_human'] ?? ($info['used_memory_human'] ?? null),
        ]);
    }

    // --------------------------------------------------------------- queues

    public function queues(): array
    {
        $connection = (string) config('queue.default');

        if (in_array($connection, ['sync', 'null'], true)) {
            return $this->row('queues', self::NOT_CONFIGURED, 'queue_is_sync', null, ['connection' => $connection]);
        }

        $pulse = Cache::get(QueueHeartbeat::KEY);
        $alive = $this->fresh($pulse);
        $failed = $this->failedJobs();

        $facts = [
            'connection' => $connection,
            'pending' => $this->pending($connection),
            'failed_24h' => $failed['count'],
        ];

        return $this->row(
            'queues',
            $alive ? self::LIVE : self::READY,
            $alive ? ($failed['count'] > 0 ? 'review_failed_jobs' : null) : 'start_worker',
            $failed['last'],
            $facts,
            is_numeric($pulse) ? (int) $pulse : null,
        );
    }

    private function pending(string $connection): ?int
    {
        try {
            return app(QueueFactory::class)->connection($connection)->size();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{count:int,last:?string} */
    private function failedJobs(): array
    {
        try {
            $since = now()->subDay();
            $query = DB::table('failed_jobs')->where('failed_at', '>=', $since);

            $last = (clone $query)->orderByDesc('id')->value('exception');

            return [
                'count' => (int) $query->count(),
                'last' => $last ? mb_substr(strtok((string) $last, "\n") ?: '', 0, 240) : null,
            ];
        } catch (Throwable) {
            return ['count' => 0, 'last' => null];
        }
    }

    // -------------------------------------------------------------- horizon

    public function horizon(): array
    {
        $installed = class_exists(Horizon::class);

        if (! $installed || config('queue.default') !== 'redis') {
            return $this->row('horizon', self::NOT_CONFIGURED, $installed ? 'queue_not_redis' : 'install_horizon', null, [
                'installed' => $installed,
            ]);
        }

        try {
            $masters = app(MasterSupervisorRepository::class)->all();
        } catch (Throwable $e) {
            return $this->row('horizon', self::READY, 'start_horizon', $this->short($e), ['installed' => true]);
        }

        $running = collect($masters)->contains(fn ($master) => ($master->status ?? null) === 'running');
        $paused = collect($masters)->contains(fn ($master) => ($master->status ?? null) === 'paused');

        return $this->row(
            'horizon',
            $running ? self::LIVE : self::READY,
            match (true) {
                $running => null,
                $paused => 'resume_horizon',
                default => 'start_horizon',
            },
            null,
            ['installed' => true, 'supervisors' => count($masters)],
        );
    }

    // -------------------------------------------------------------- backups

    public function backups(): array
    {
        $status = RecordBackup::read();

        if ($status === null) {
            return $this->row('backups', self::NOT_CONFIGURED, 'schedule_backups', null, []);
        }

        $at = isset($status['at']) ? strtotime((string) $status['at']) : null;
        $fresh = $at !== null && (time() - $at) <= self::BACKUP_FRESH_HOURS * 3600;
        $offsite = (bool) ($status['offsite'] ?? false);

        $facts = [
            'size' => $status['size'] ?? null,
            'off_server' => $offsite,
        ];

        if (! ($status['ok'] ?? false)) {
            return $this->row('backups', self::NOT_CONFIGURED, 'backup_failed', (string) ($status['error'] ?? 'The last backup failed.'), $facts, $at);
        }

        // A copy on the same machine survives a bad migration and does not
        // survive the machine. It is "ready" — it exists — and not "verified".
        $state = match (true) {
            $fresh && $offsite => self::LIVE,
            default => self::READY,
        };

        return $this->row('backups', $state, match (true) {
            ! $fresh => 'backup_stale',
            ! $offsite => 'offsite_copy',
            default => null,
        }, null, $facts, $at);
    }

    // ----------------------------------------------------------------- logs

    public function logs(): array
    {
        $default = (string) config('logging.default');
        $dir = storage_path('logs');
        $channels = $default === 'stack' ? (array) config('logging.channels.stack.channels') : [$default];

        if (in_array('null', $channels, true) && count($channels) === 1) {
            return $this->row('logs', self::NOT_CONFIGURED, 'enable_logging', null, ['channel' => $default]);
        }

        // Written and read back: a log volume that is mounted read-only, or
        // full, is the kind of failure that is discovered by wanting a log.
        $probe = $dir.'/.readiness';
        $stamp = (string) time();

        $writable = is_dir($dir) && @file_put_contents($probe, $stamp) !== false && @file_get_contents($probe) === $stamp;

        if (! $writable) {
            return $this->row('logs', self::NOT_CONFIGURED, 'logs_not_writable', 'The log directory could not be written to.', ['channel' => $default]);
        }

        $rotates = in_array('daily', $channels, true) || in_array('stderr', $channels, true);

        return $this->row(
            'logs',
            $rotates ? self::LIVE : self::READY,
            $rotates ? null : 'rotate_logs',
            $this->lastLoggedError($channels),
            ['channel' => $default, 'rotates' => $rotates],
        );
    }

    /** @param array<int,string> $channels */
    private function lastLoggedError(array $channels): ?string
    {
        $files = glob(storage_path('logs/laravel*.log')) ?: [];

        if ($files === []) {
            return null;
        }

        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $file = $files[0];

        $size = filesize($file) ?: 0;
        $handle = @fopen($file, 'rb');

        if (! $handle) {
            return null;
        }

        // The tail only: a log can be gigabytes, and this runs on a page load.
        fseek($handle, max(0, $size - 65536));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        if (preg_match_all('/^\[(\d{4}-\d{2}-\d{2}[ T][\d:]+)[^\]]*\]\s+\S+\.(?:ERROR|CRITICAL|ALERT|EMERGENCY):\s*(.+)$/m', $tail, $m, PREG_SET_ORDER) < 1) {
            return null;
        }

        $last = end($m);

        return mb_substr($last[1].' — '.$last[2], 0, 240);
    }

    // ------------------------------------------------------- error tracking

    public function errorTracking(): array
    {
        $dsn = (string) (config('sentry.dsn') ?: env('SENTRY_LARAVEL_DSN', ''));
        $package = class_exists(ServiceProvider::class);

        if (! $package) {
            return $this->row('error_tracking', self::NOT_CONFIGURED, 'install_error_tracker', null, ['installed' => false]);
        }

        if ($dsn === '') {
            return $this->row('error_tracking', self::NOT_CONFIGURED, 'set_error_dsn', null, ['installed' => true]);
        }

        // The most this can honestly be. The SDK is installed and has a
        // destination; whether an event ever arrives is something only the
        // tracker's own inbox can say, and no amount of reading config can.
        return $this->row('error_tracking', self::READY, 'confirm_test_event', null, ['installed' => true]);
    }

    // -------------------------------------------------------------- helpers

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    private function row(string $key, string $state, ?string $action, ?string $error, array $facts, ?int $checkedAt = null): array
    {
        return [
            'key' => $key,
            'state' => $state,
            // The older, three-word vocabulary, so the same screen can use one
            // badge for every row on it.
            'status' => match ($state) {
                self::LIVE => ProductionReadiness::LIVE,
                self::READY => ProductionReadiness::READY,
                default => ProductionReadiness::EXTERNAL,
            },
            // When the thing was last *seen* — for a heartbeat that is when it
            // beat, for a probe it is now.
            'last_check' => ($checkedAt !== null ? Carbon::createFromTimestamp($checkedAt) : now())->toIso8601String(),
            'last_error' => $error,
            'required_action' => $action,
            'facts' => $facts,
        ];
    }

    private function fresh(mixed $timestamp): bool
    {
        return is_numeric($timestamp) && (time() - (int) $timestamp) <= self::PULSE_SECONDS;
    }

    /** What went wrong, in a line, with nothing in it that is a secret. */
    private function short(Throwable $e): string
    {
        $message = preg_replace('/(password|secret|token)=\S+/i', '$1=***', $e->getMessage()) ?? $e->getMessage();

        return mb_substr(strtok($message, "\n") ?: $message, 0, 240);
    }
}
