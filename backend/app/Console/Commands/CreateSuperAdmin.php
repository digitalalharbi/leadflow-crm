<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateSuperAdmin extends Command
{
    protected $signature = 'leadflow:create-super-admin
                            {--email= : Super admin email}
                            {--name= : Display name}
                            {--password= : Password (generated when omitted)}';

    protected $description = 'Create the platform super admin (belongs to no tenant)';

    public function handle(): int
    {
        $email = Str::lower($this->option('email') ?: (string) config('tenancy.super_admin.email'));
        $name = $this->option('name') ?: (string) config('tenancy.super_admin.name');

        if (! $email) {
            $this->error('Provide --email or set SUPER_ADMIN_EMAIL.');

            return self::FAILURE;
        }

        if (User::whereNull('tenant_id')->where('email', $email)->exists()) {
            $this->warn("Super admin {$email} already exists.");

            return self::SUCCESS;
        }

        $password = $this->option('password') ?: Str::password(16);

        User::create([
            'tenant_id' => null,
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => Role::SuperAdmin,
            'is_active' => true,
        ]);

        $this->info("Super admin created: {$email}");

        if (! $this->option('password')) {
            $this->line('Generated password: '.$password);
            $this->warn('Store it now — it is not shown again.');
        }

        return self::SUCCESS;
    }
}
