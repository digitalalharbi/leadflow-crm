<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** A deploy once rolled back because a value with a space in it ran as a command when the backup sourced the file. */
class EnvFileLoadingTest extends TestCase
{
    public function test_an_env_file_is_read_as_data_and_nothing_in_it_runs(): void
    {
        $dir = sys_get_temp_dir().'/env-test-'.uniqid();
        mkdir($dir);
        $marker = $dir.'/ran';
        file_put_contents($dir.'/env', implode("\n", [
            '# a comment',
            '',
            'DB_USERNAME=leadflow',
            'WITH_SPACE=part one touch '.$marker,
            'SUBSTITUTION=$(touch '.$marker.')',
            'SEMI=a;touch '.$marker,
            'QUOTED="مهتم — Mohtam"',
            "SINGLE='x y'",
            'not a line at all',
        ]));

        $lib = base_path('../scripts/lib/env-file.sh');
        $result = Process::run(['bash', '-c', 'set -Eeuo pipefail; . "$0"; load_env_file "$1"; printf "%s|%s|%s|%s|%s" "$DB_USERNAME" "$WITH_SPACE" "$QUOTED" "$SINGLE" "$SEMI"', $lib, $dir.'/env']);

        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->assertSame('leadflow|part one touch '.$marker.'|مهتم — Mohtam|x y|a;touch '.$marker, $result->output());
        $this->assertFileDoesNotExist($marker);

        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }

    public function test_no_backup_or_restore_script_sources_the_env_file(): void
    {
        foreach (['backup-db.sh', 'restore-check.sh', 'restore-db.sh'] as $script) {
            $this->assertStringNotContainsString('source "$ENV_FILE"', (string) file_get_contents(base_path('../scripts/'.$script)), $script);
        }
    }
}
