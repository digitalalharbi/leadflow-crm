<?php

namespace App\Services\Tenancy;

use App\Enums\IntegrationProvider;
use App\Enums\Role;
use App\Models\Integration;
use App\Models\LostReason;
use App\Models\MessageTemplate;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Everything a new client needs to start working, created in one transaction.
 * This is the whole "onboard a customer" story — no code change, no migration,
 * no manual SQL.
 */
class TenantProvisioningService
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return array{tenant: Tenant, admin: User, webhook_secret: string}
     */
    public function provision(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $defaults = config('tenancy.defaults');

            $tenant = Tenant::create([
                'name' => $data['company_name'],
                'slug' => $this->uniqueSlug($data['subdomain'] ?? $data['company_name']),
                'plan' => $data['plan'] ?? $defaults['plan'],
                'subscription_status' => 'trialing',
                'trial_ends_at' => now()->addDays((int) ($data['trial_days'] ?? $defaults['trial_days'])),
                'is_active' => true,
            ]);

            // Everything below is tenant-scoped, so enter the context first.
            return $this->context->withTenant($tenant, function () use ($tenant, $data, $defaults) {
                TenantBranding::create([
                    'company_name' => $data['company_name'],
                    'system_name' => $data['system_name'] ?? $data['company_name'],
                    'logo_url' => $data['logo_url'] ?? null,
                    'primary_color' => $data['primary_color'] ?? '#2563eb',
                    'secondary_color' => $data['secondary_color'] ?? '#0f172a',
                    'support_email' => $data['support_email'] ?? null,
                    'support_phone' => $data['support_phone'] ?? null,
                ]);

                TenantSetting::create([
                    'locale' => $data['locale'] ?? $defaults['locale'],
                    'currency' => $data['currency'] ?? $defaults['currency'],
                    'timezone' => $data['timezone'] ?? $defaults['timezone'],
                    'default_country_code' => $data['country_code'] ?? $defaults['country_code'],
                    'assignment_mode' => $data['assignment_mode'] ?? 'manual',
                    'whatsapp_template' => 'مرحباً {{name}}، معك {{agent}} من {{company}} بخصوص اهتمامك بمشروع {{project}}.',
                    'onboarding' => $this->initialOnboarding(),
                ]);

                // A workspace with no saved messages has a composer nobody uses.
                foreach (MessageTemplate::DEFAULTS as $index => $template) {
                    MessageTemplate::create([
                        'name' => $template['name'],
                        'body' => $template['body'],
                        'channel' => 'whatsapp',
                        'is_active' => true,
                        'sort_order' => $index,
                    ]);
                }

                foreach (LostReason::DEFAULTS as $index => $reason) {
                    LostReason::create([
                        'key' => $reason['key'],
                        'label' => $reason['label'],
                        'sort_order' => $index,
                    ]);
                }

                $admin = User::create([
                    'tenant_id' => $tenant->id,
                    'name' => $data['admin_name'],
                    'email' => Str::lower($data['admin_email']),
                    'password' => $data['admin_password'] ?? Str::random(32),
                    'role' => Role::Admin,
                    'is_active' => true,
                    'locale' => $data['locale'] ?? $defaults['locale'],
                ]);

                // Every tenant gets a working inbound endpoint from minute one.
                $integration = Integration::create([
                    'provider' => IntegrationProvider::GenericWebhook,
                    'name' => 'Default Webhook',
                    'status' => Integration::CONNECTED,
                    'field_mapping' => [],
                ]);

                [$endpoint, $secret] = WebhookEndpoint::issue($integration->id, 'Default Webhook');

                return [
                    'tenant' => $tenant->fresh(['branding', 'settings']),
                    'admin' => $admin,
                    'webhook_key' => $endpoint->key,
                    'webhook_secret' => $secret,
                ];
            });
        });
    }

    /** The short checklist the admin sees on first login. */
    private function initialOnboarding(): array
    {
        return [
            'branding' => true,       // provisioned above
            'team' => false,
            'project' => false,
            'lead_source' => false,
            'first_lead' => false,
        ];
    }

    public function uniqueSlug(string $candidate): string
    {
        $base = Str::slug(Str::ascii($candidate)) ?: 'tenant';
        $base = Str::limit($base, 40, '');

        if (in_array($base, config('tenancy.reserved_slugs'), true)) {
            $base .= '-crm';
        }

        $slug = $base;
        $i = 2;

        while (Tenant::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
