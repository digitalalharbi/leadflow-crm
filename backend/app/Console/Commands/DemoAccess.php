<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sets one shared sign-in password for the sales demo workspace, typed at a hidden prompt.
 *
 * The demo's people use a reserved test domain, so no mail can reach them and the reset link is no way in. This is the
 * way in instead. The password is never an argument, never echoed, never logged; only the workspace and how many
 * accounts changed are. It touches the demo workspace and nothing else.
 */
class DemoAccess extends Command
{
    public const SLUG = 'mohtam-demo';

    protected $signature = 'mohtam:demo-access';

    protected $description = 'Set the sign-in password of the sales demo workspace (hidden prompt)';

    public function handle(TenantContext $context): int
    {
        $tenant = $context->withoutScope(fn () => Tenant::where('slug', self::SLUG)->first());

        if ($tenant === null) {
            $this->error('The demo workspace ('.self::SLUG.') does not exist. Nothing changed.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Demo password (at least 12 characters)');
        $again = (string) $this->secret('Type it again');

        if (strlen($password) < 12 || $password !== $again) {
            $this->error('Too short, or the two entries differ. Nothing changed.');

            return self::FAILURE;
        }

        $changed = DB::transaction(function () use ($context, $tenant, $password) {
            return $context->withTenant($tenant, function () use ($tenant, $password) {
                $users = User::query()->where('tenant_id', $tenant->id)->get();

                foreach ($users as $user) {
                    $user->forceFill(['password' => $password, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
                }

                return $users->count();
            });
        });

        Log::info('demo.access_set', ['workspace' => self::SLUG, 'accounts' => $changed]);
        $this->info("Demo password set for {$changed} accounts in ".self::SLUG.'. Sign in as admin@leadflow-demo.test.');

        return self::SUCCESS;
    }
}
