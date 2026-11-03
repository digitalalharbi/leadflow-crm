<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** The older name of mohtam:bootstrap-admin. It no longer takes or prints a password. */
class CreateSuperAdmin extends Command
{
    protected $signature = 'leadflow:create-super-admin
                            {--email= : Super admin email}
                            {--name= : Display name}';

    protected $description = 'Create the platform super admin (same as mohtam:bootstrap-admin)';

    public function handle(): int
    {
        return $this->call('mohtam:bootstrap-admin', [
            '--email' => $this->option('email') ?: config('tenancy.super_admin.email'),
            '--name' => $this->option('name') ?: config('tenancy.super_admin.name'),
        ]);
    }
}
