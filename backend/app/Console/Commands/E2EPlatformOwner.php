<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;

/** A platform owner for the browser tests, with the password from the environment. Never in production. */
class E2EPlatformOwner extends Command
{
    protected $signature = 'leadflow:e2e-platform-owner {email}';

    protected $description = 'Create the platform owner the end-to-end tests sign in as';

    public function handle(): int
    {
        $password = (string) env('E2E_PLATFORM_OWNER_PASSWORD');

        if (app()->environment('production') || $password === '') {
            $this->error('Refused: not in production, and only with E2E_PLATFORM_OWNER_PASSWORD set.');

            return self::FAILURE;
        }

        User::query()->firstOrCreate(
            ['tenant_id' => null, 'email' => strtolower((string) $this->argument('email'))],
            ['name' => 'Platform Owner', 'password' => $password, 'role' => Role::SuperAdmin, 'is_active' => true],
        );

        return self::SUCCESS;
    }
}
