<?php

namespace Tests\Feature;

use App\Console\Commands\RecordBackup;
use App\Domain\Readiness\InfrastructureReadiness;
use App\Enums\Role;
use App\Jobs\QueueHeartbeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What the platform stands on, answered from evidence.
 *
 * One idea runs through every test here: a configuration value is never proof.
 * `QUEUE_CONNECTION=redis` says what somebody intended; a heartbeat job that ran
 * says what happened. A row goes green only for the second.
 */
class InfrastructureReadinessTest extends TestCase
{
    use RefreshDatabase;

    private InfrastructureReadiness $infra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->infra = app(InfrastructureReadiness::class);
        @unlink(RecordBackup::path());
    }

    protected function tearDown(): void
    {
        @unlink(RecordBackup::path());
        parent::tearDown();
    }

    private function row(string $key): array
    {
        return collect($this->infra->all())->firstWhere('key', $key);
    }

    public function test_the_last_logged_error_shows_the_message_not_where_the_code_lives(): void
    {
        $file = storage_path('logs/laravel-readiness-test.log');
        file_put_contents($file, '['.now()->format('Y-m-d H:i:s').'] testing.ERROR: Class "X" not found {"exception":"[object] (Error at '.base_path('app/Thing.php').':12)"}'.PHP_EOL);
        touch($file, time() + 60);

        try {
            $error = $this->row('logs')['last_error'];
        } finally {
            @unlink($file);
        }

        $this->assertStringContainsString('Class "X" not found', $error);
        $this->assertStringNotContainsString(base_path(), $error);
        $this->assertStringNotContainsString('exception', $error);
    }

    public function test_a_queued_jobs_failure_does_not_show_where_the_code_lives_even_escaped(): void
    {
        $file = storage_path('logs/laravel-readiness-test.log');
        $escaped = str_replace('/', '\\/', base_path('vendor/laravel/framework/src/Builder.php'));
        file_put_contents($file, '['.now()->format('Y-m-d H:i:s').'] testing.ERROR: Illuminate\\Database\\Eloquent\\ModelNotFoundException: No query results for model [App\\Models\\Lead]. in '.$escaped.':780'.PHP_EOL);
        touch($file, time() + 60);

        try {
            $error = $this->row('logs')['last_error'];
        } finally {
            @unlink($file);
        }

        $this->assertStringContainsString('No query results', $error);
        $this->assertStringNotContainsString('vendor', $error);
        $this->assertStringNotContainsString(basename(dirname(base_path())), $error);
    }

    public function test_a_failed_job_shows_what_failed_not_where(): void
    {
        $line = 'Illuminate\\Database\\Eloquent\\ModelNotFoundException: No query results for model [App\\Models\\Lead]. in '.base_path('vendor/laravel/framework/src/Builder.php').':780';

        $shown = InfrastructureReadiness::shown($line);

        $this->assertStringContainsString('No query results', $shown);
        $this->assertStringNotContainsString('vendor', $shown);
    }

    public function test_a_database_error_shows_its_kind_not_the_connection_or_the_sql(): void
    {
        $file = storage_path('logs/laravel-readiness-test.log');
        file_put_contents($file, '['.now()->format('Y-m-d H:i:s').'] testing.ERROR: SQLSTATE[23505]: Unique violation: duplicate key (Connection: pgsql, Host: 10.0.0.5, Port: 5432, Database: crm_prod, SQL: insert into "leads" ("phone") values (+966551234567))'.PHP_EOL);
        touch($file, time() + 60);

        try {
            $error = $this->row('logs')['last_error'];
        } finally {
            @unlink($file);
        }

        $this->assertStringContainsString('Unique violation', $error);
        $this->assertStringNotContainsString('crm_prod', $error);
        $this->assertStringNotContainsString('10.0.0.5', $error);
        $this->assertStringNotContainsString('+966551234567', $error);
    }

    // --------------------------------------------------------------- shape

    public function test_there_are_seven_rows_each_with_the_same_facts(): void
    {
        $rows = $this->infra->all();

        $this->assertSame(
            ['database', 'redis', 'queues', 'horizon', 'backups', 'logs', 'error_tracking'],
            array_column($rows, 'key'),
        );

        foreach ($rows as $row) {
            foreach (['state', 'status', 'last_check', 'last_error', 'required_action', 'facts'] as $field) {
                $this->assertArrayHasKey($field, $row, "{$row['key']} lacks $field");
            }
            $this->assertContains($row['state'], ['not_configured', 'ready', 'live_verified']);
        }
    }

    public function test_the_older_status_word_agrees_with_the_state(): void
    {
        foreach ($this->infra->all() as $row) {
            $this->assertSame(match ($row['state']) {
                'live_verified' => 'live_verified',
                'ready' => 'ready',
                default => 'external_verification_required',
            }, $row['status']);
        }
    }

    // ------------------------------------------------------------ database

    public function test_a_database_that_answers_with_no_pending_migrations_is_live(): void
    {
        $row = $this->row('database');

        $this->assertSame('live_verified', $row['state']);
        $this->assertSame(0, $row['facts']['pending_migrations']);
        $this->assertNull($row['required_action']);
    }

    /** Answering is the observation — but a schema behind the code is a half-done deployment. */
    public function test_pending_migrations_make_it_ready_not_live(): void
    {
        DB::table('migrations')->orderByDesc('id')->limit(1)->delete();

        $row = $this->row('database');

        $this->assertSame('ready', $row['state']);
        $this->assertSame(1, $row['facts']['pending_migrations']);
        $this->assertSame('run_migrations', $row['required_action']);
    }

    // --------------------------------------------------------------- redis

    public function test_redis_that_nothing_uses_is_not_configured(): void
    {
        config(['queue.default' => 'sync', 'cache.default' => 'array', 'session.driver' => 'array']);

        $row = $this->row('redis');

        $this->assertSame('not_configured', $row['state']);
        $this->assertSame('use_redis', $row['required_action']);
    }

    public function test_redis_that_is_configured_and_does_not_answer_says_so(): void
    {
        config([
            'queue.default' => 'redis',
            'database.redis.default.host' => '127.0.0.1',
            'database.redis.default.port' => 1,
            'database.redis.default.timeout' => 1,
        ]);
        app('redis')->purge();

        $row = $this->row('redis');

        $this->assertSame('not_configured', $row['state']);
        $this->assertSame('redis_unreachable', $row['required_action']);
        $this->assertNotEmpty($row['last_error']);
    }

    // -------------------------------------------------------------- queues

    public function test_a_sync_queue_is_not_a_queue(): void
    {
        config(['queue.default' => 'sync']);

        $row = $this->row('queues');

        $this->assertSame('not_configured', $row['state']);
        $this->assertSame('queue_is_sync', $row['required_action']);
    }

    /** The configuration says redis; only a job that ran says there is a worker. */
    public function test_a_configured_queue_with_no_worker_is_ready_not_live(): void
    {
        config(['queue.default' => 'redis']);
        Cache::forget(QueueHeartbeat::KEY);

        $row = $this->row('queues');

        $this->assertSame('ready', $row['state']);
        $this->assertSame('start_worker', $row['required_action']);
    }

    public function test_a_fresh_heartbeat_makes_the_queue_live(): void
    {
        config(['queue.default' => 'redis']);
        Cache::put(QueueHeartbeat::KEY, time(), 600);

        $this->assertSame('live_verified', $this->row('queues')['state']);
    }

    public function test_a_stale_heartbeat_is_no_heartbeat(): void
    {
        config(['queue.default' => 'redis']);
        Cache::put(QueueHeartbeat::KEY, time() - 3600, 7200);

        $this->assertSame('ready', $this->row('queues')['state']);
    }

    public function test_the_last_check_is_when_the_pulse_was_not_when_it_was_asked(): void
    {
        config(['queue.default' => 'redis']);
        $beat = time() - 90;
        Cache::put(QueueHeartbeat::KEY, $beat, 600);

        $this->assertSame($beat, strtotime($this->row('queues')['last_check']));
    }

    public function test_failed_jobs_are_counted_and_the_last_one_is_named(): void
    {
        config(['queue.default' => 'redis']);
        Cache::put(QueueHeartbeat::KEY, time(), 600);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default',
            'payload' => '{}', 'exception' => "RuntimeException: boom\n#0 trace", 'failed_at' => now(),
        ]);

        $row = $this->row('queues');

        $this->assertSame(1, $row['facts']['failed_24h']);
        $this->assertSame('RuntimeException: boom', $row['last_error']);
        $this->assertSame('review_failed_jobs', $row['required_action']);
    }

    public function test_a_failure_from_last_week_is_history(): void
    {
        config(['queue.default' => 'redis']);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'Old', 'failed_at' => now()->subDays(5),
        ]);

        $this->assertSame(0, $this->row('queues')['facts']['failed_24h']);
    }

    // ------------------------------------------------------------- horizon

    public function test_horizon_waits_for_a_redis_queue(): void
    {
        config(['queue.default' => 'sync']);

        $row = $this->row('horizon');

        $this->assertSame('not_configured', $row['state']);
        $this->assertSame('queue_not_redis', $row['required_action']);
    }

    public function test_horizon_that_is_installed_but_not_running_is_ready_not_live(): void
    {
        config(['queue.default' => 'redis']);

        $row = $this->row('horizon');

        $this->assertNotSame('live_verified', $row['state']);
    }

    // ------------------------------------------------------------- backups

    public function test_no_backup_has_ever_been_recorded(): void
    {
        $row = $this->row('backups');

        $this->assertSame('not_configured', $row['state']);
        $this->assertSame('schedule_backups', $row['required_action']);
    }

    public function test_a_fresh_backup_copied_off_the_server_is_live(): void
    {
        $this->artisan('leadflow:record-backup', ['--size' => '12M', '--offsite' => true])->assertSuccessful();

        $row = $this->row('backups');

        $this->assertSame('live_verified', $row['state']);
        $this->assertSame('12M', $row['facts']['size']);
        $this->assertTrue($row['facts']['off_server']);
    }

    /** A copy on the same machine survives a bad migration and not the machine. */
    public function test_a_backup_that_never_left_the_server_is_only_ready(): void
    {
        $this->artisan('leadflow:record-backup', ['--size' => '12M'])->assertSuccessful();

        $row = $this->row('backups');

        $this->assertSame('ready', $row['state']);
        $this->assertSame('offsite_copy', $row['required_action']);
    }

    public function test_a_backup_from_two_nights_ago_has_missed_one(): void
    {
        file_put_contents(RecordBackup::path(), json_encode([
            'at' => now()->subHours(50)->toIso8601String(), 'ok' => true, 'size' => '1M', 'offsite' => true,
        ]));

        $row = $this->row('backups');

        $this->assertSame('ready', $row['state']);
        $this->assertSame('backup_stale', $row['required_action']);
    }

    public function test_a_failed_backup_says_why_and_is_not_configured(): void
    {
        $this->artisan('leadflow:record-backup', ['--failed' => 'pg_dump could not connect'])->assertSuccessful();

        $row = $this->row('backups');

        $this->assertSame('not_configured', $row['state']);
        $this->assertSame('backup_failed', $row['required_action']);
        $this->assertSame('pg_dump could not connect', $row['last_error']);
    }

    public function test_the_last_good_backup_survives_a_failure(): void
    {
        $this->artisan('leadflow:record-backup', ['--size' => '1M', '--offsite' => true])->assertSuccessful();
        $good = RecordBackup::read()['at'];

        $this->artisan('leadflow:record-backup', ['--failed' => 'disk full'])->assertSuccessful();

        $this->assertSame($good, RecordBackup::read()['last_good_at']);
    }

    // ---------------------------------------------------------------- logs

    public function test_rotating_logs_are_live_and_a_single_file_is_only_ready(): void
    {
        config(['logging.default' => 'daily']);
        $this->assertSame('live_verified', $this->row('logs')['state']);

        config(['logging.default' => 'single']);
        $row = $this->row('logs');
        $this->assertSame('ready', $row['state']);
        $this->assertSame('rotate_logs', $row['required_action']);
    }

    public function test_logging_to_nowhere_is_not_configured(): void
    {
        config(['logging.default' => 'null']);

        $this->assertSame('not_configured', $this->row('logs')['state']);
    }

    // ------------------------------------------------------ error tracking

    public function test_without_a_tracker_installed_there_is_nothing_to_verify(): void
    {
        $row = $this->row('error_tracking');

        $this->assertSame('not_configured', $row['state']);
        $this->assertSame('install_error_tracker', $row['required_action']);
    }

    // ------------------------------------------------------------- secrets

    public function test_an_error_message_never_carries_a_secret(): void
    {
        $short = new \ReflectionMethod($this->infra, 'short');

        $message = $short->invoke($this->infra, new \RuntimeException('Connection failed password=hunter2 secret=abc token=xyz'));

        $this->assertStringNotContainsString('hunter2', $message);
        $this->assertStringNotContainsString('abc', $message);
        $this->assertStringNotContainsString('xyz', $message);
    }

    // ------------------------------------------------------------ endpoints

    public function test_the_workspace_report_carries_the_infrastructure_apart_from_the_integrations(): void
    {
        $t = $this->provisionTenant('Infra Co');

        $response = $this->actingAs($t['admin'])->getJson('/api/v1/readiness')->assertOk();

        $this->assertCount(7, $response->json('data.infrastructure'));
        $this->assertNotContains('database', array_column($response->json('data.items'), 'key'));
        $this->assertArrayHasKey('infrastructure_summary', $response->json('data'));
    }

    public function test_a_rep_cannot_read_it(): void
    {
        $t = $this->provisionTenant('Infra Co');
        $rep = $this->makeUser($t['tenant'], Role::Sales);

        $this->actingAs($rep)->getJson('/api/v1/readiness')->assertForbidden();
    }

    public function test_the_platform_report_carries_it_too(): void
    {
        $owner = User::factory()->create(['role' => Role::SuperAdmin, 'tenant_id' => null]);

        $this->actingAs($owner)->getJson('/api/v1/super-admin/readiness')->assertOk()
            ->assertJsonCount(7, 'data.infrastructure');
    }
}
