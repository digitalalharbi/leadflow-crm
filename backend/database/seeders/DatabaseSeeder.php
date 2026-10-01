<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->createPlatformOwner();

        if (! app()->environment('production')) {
            $this->call(DemoSeeder::class);
        }
    }

    /**
     * The platform owner can read and rebrand every tenant, so it must never be
     * created with a default password. Outside local development the password
     * has to be supplied explicitly, or seeding stops.
     */
    private function createPlatformOwner(): void
    {
        $email = strtolower((string) config('tenancy.super_admin.email'));
        $password = config('tenancy.super_admin.password');

        if ($email === '') {
            $this->command?->warn('SUPER_ADMIN_EMAIL is not set; skipping the platform owner.');

            return;
        }

        if (User::whereNull('tenant_id')->where('email', $email)->exists()) {
            return;
        }

        if (blank($password)) {
            if (! app()->environment('local', 'testing')) {
                throw new RuntimeException(
                    'SUPER_ADMIN_PASSWORD must be set before seeding outside local development. '
                    .'Use `php artisan leadflow:create-super-admin` to generate one instead.'
                );
            }

            $password = 'password';
            $this->command?->warn('Using the development default password for the platform owner.');
        }

        User::create([
            'tenant_id' => null,
            'name' => config('tenancy.super_admin.name'),
            'email' => $email,
            'password' => $password,
            'role' => Role::SuperAdmin,
            'is_active' => true,
        ]);
    }
}
