<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The first platform owner, from a shell on the server. The password is typed at a hidden prompt, so it is in no command
 * line, shell history, log or file. Running it twice changes nothing. Companies and their people come after this, from
 * the console and by invitation; the first platform sign-in asks for a second factor.
 */
class BootstrapAdmin extends Command
{
    protected $signature = 'mohtam:bootstrap-admin
                            {--email= : Their email}
                            {--name= : Their name}';

    protected $description = 'Create the first platform owner at a hidden password prompt';

    public function handle(): int
    {
        $email = Str::lower(trim((string) ($this->option('email') ?: $this->ask('Email'))));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->error('That is not an email address.');

            return self::FAILURE;
        }

        if (User::query()->whereNull('tenant_id')->where('email', $email)->exists()) {
            $this->warn("{$email} is already a platform owner. Nothing changed.");

            return self::SUCCESS;
        }

        $name = trim((string) ($this->option('name') ?: $this->ask('Name')));

        if ($name === '') {
            $this->error('A name is needed.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (hidden, at least 12 characters)');

        if (mb_strlen($password) < 12) {
            $this->error('The password must be at least 12 characters.');

            return self::FAILURE;
        }

        if (! hash_equals($password, (string) $this->secret('Password again'))) {
            $this->error('The two passwords differ.');

            return self::FAILURE;
        }

        $user = User::create([
            'tenant_id' => null,
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => Role::SuperAdmin,
            'is_active' => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        Log::info('platform.owner_bootstrapped', ['email_hash' => hash('sha256', $email)]);
        $this->info("Platform owner created: {$email}. Sign in at /platform/login.");

        return self::SUCCESS;
    }
}
