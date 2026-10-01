<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Platform owner. Exists in every environment; the password comes from env
        // in staging/production and is never committed.
        $email = strtolower((string) env('SUPER_ADMIN_EMAIL', 'owner@example.com'));

        if (! User::whereNull('tenant_id')->where('email', $email)->exists()) {
            User::create([
                'tenant_id' => null,
                'name' => env('SUPER_ADMIN_NAME', 'Platform Owner'),
                'email' => $email,
                'password' => env('SUPER_ADMIN_PASSWORD', 'password'),
                'role' => Role::SuperAdmin,
                'is_active' => true,
            ]);
        }

        if (! app()->environment('production')) {
            $this->call(DemoSeeder::class);
        }
    }
}
