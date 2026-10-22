<?php

namespace App\Console\Commands;

use Database\Seeders\E2ESeeder;
use Illuminate\Console\Command;

/**
 * Resets the E2E fixtures to their known state.
 *
 * Playwright calls this between runs, so a suite always starts from the same
 * data regardless of what a previous run left behind.
 */
class SeedE2E extends Command
{
    protected $signature = 'leadflow:seed-e2e';

    protected $description = 'Reset the deterministic end-to-end test fixtures';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to seed test fixtures in production.');

            return self::FAILURE;
        }

        $this->call('db:seed', ['--class' => E2ESeeder::class, '--force' => true]);

        return self::SUCCESS;
    }
}
