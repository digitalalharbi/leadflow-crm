<?php

namespace App\Console\Commands;

use App\Domain\Mail\EmailDeliveries;
use App\Models\Tenant;
use App\Services\Tenancy\TenantProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * A company's workspace from a shell on the server, the same provisioning the platform console runs: branding,
 * settings, message templates, the lead journey (installed and off), lost reasons, the owner, and a webhook endpoint.
 *
 * The owner gets no password from here. A setup link is emailed to them and they choose their own, so no secret is
 * typed, printed or logged. The slug is explicit and an existing one is refused, so running it twice changes nothing
 * and never leaves a second workspace behind.
 */
class CreateWorkspace extends Command
{
    protected $signature = 'mohtam:create-workspace
                            {--company= : The company name}
                            {--slug= : Its address, lowercase letters, digits and dashes}
                            {--admin-name= : The owner\'s name}
                            {--admin-email= : The owner\'s email: a real mailbox, never a reserved test domain}
                            {--system-name= : The product name shown in this workspace, if not Mohtam}';

    protected $description = 'Create a company workspace and email its owner a link to choose a password';

    public function handle(TenantProvisioningService $provisioning): int
    {
        $data = [
            'company_name' => trim((string) ($this->option('company') ?: $this->ask('Company name'))),
            'subdomain' => Str::lower(trim((string) ($this->option('slug') ?: $this->ask('Slug (lowercase letters, digits, dashes)')))),
            'admin_name' => trim((string) ($this->option('admin-name') ?: $this->ask('Owner name'))),
            'admin_email' => Str::lower(trim((string) ($this->option('admin-email') ?: $this->ask('Owner email')))),
            'system_name' => $this->option('system-name') ?: null,
        ];

        $v = Validator::make($data, [
            'company_name' => ['required', 'string', 'max:255'],
            'subdomain' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'not_in:'.implode(',', (array) config('tenancy.reserved_slugs'))],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
        ]);

        if ($v->fails()) {
            foreach ($v->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (EmailDeliveries::isReserved($data['admin_email'])) {
            $this->error('That address is on a domain reserved for testing and can never receive the setup link. Use a real mailbox.');

            return self::FAILURE;
        }

        if (Tenant::withTrashed()->where('slug', $data['subdomain'])->exists()) {
            $this->warn("A workspace with the slug {$data['subdomain']} already exists. Nothing changed.");

            return self::SUCCESS;
        }

        // No password: the owner chooses one from the emailed link.
        $result = $provisioning->provision($data + ['assignment_mode' => 'round_robin']);
        $tenant = $result['tenant'];

        Log::info('platform.workspace_created_from_shell', ['slug' => $tenant->slug, 'owner_hash' => hash('sha256', $data['admin_email'])]);

        $this->info("Workspace created: {$tenant->slug}");
        $this->line('Owner: '.$data['admin_email'].($result['setup_link_sent'] ? ' (setup link emailed)' : ''));
        $this->line('The webhook secret is not shown here: issue a new one under Integrations when you connect a source.');

        return self::SUCCESS;
    }
}
