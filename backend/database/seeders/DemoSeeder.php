<?php

namespace Database\Seeders;

use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\CustomField;
use App\Models\LostReason;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\TenantContext;
use Database\Seeders\Support\DemoContent;
use Database\Seeders\Support\LeadFabricator;
use Illuminate\Database\Seeder;

/**
 * Builds a staging environment that can be shown to a prospect without
 * apologising for it: believable Arabic names, campaigns named the way a media
 * buyer names them, and a funnel with the shape a sales manager recognises.
 *
 * Local and staging only — it refuses to run in production.
 */
class DemoSeeder extends Seeder
{
    /**
     * A funnel, not a uniform spread. Most leads sit early; a few are won.
     * These numbers produce a dashboard that looks like a working month.
     */
    private const FUNNEL = [
        LeadStatus::New->value => 48,
        LeadStatus::Contacted->value => 42,
        LeadStatus::Qualified->value => 31,
        LeadStatus::Appointment->value => 18,
        LeadStatus::Won->value => 14,
        LeadStatus::Lost->value => 27,
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DemoSeeder refuses to run in production.');

            return;
        }

        $context = app(TenantContext::class);
        $provisioning = app(TenantProvisioningService::class);

        // Idempotent: re-running replaces the demo tenant cleanly.
        $existing = $context->withoutScope(fn () => Tenant::where('slug', 'rawasi')->first());
        $existing && $context->withoutScope(fn () => $existing->forceDelete());

        $result = $provisioning->provision([
            'company_name' => 'شركة رواسي للتطوير العقاري',
            'subdomain' => 'rawasi',
            'primary_color' => '#0f766e',
            'secondary_color' => '#0f172a',
            'admin_name' => 'عبدالله الحربي',
            'admin_email' => 'admin@rawasi.test',
            'admin_password' => 'password',
            'support_email' => 'support@rawasi.test',
            'support_phone' => '+966112345678',
            'assignment_mode' => 'round_robin',
            'plan' => 'growth',
            'trial_days' => 30,
        ]);

        $tenant = $result['tenant'];

        $context->withTenant($tenant, function () use ($tenant, $result) {
            $this->command?->info('Seeding demo tenant: '.$tenant->name);

            $assignees = $this->seedTeam($tenant);
            $projects = $this->seedProjects($tenant);
            $this->seedCustomFields($tenant);

            $fabricator = new LeadFabricator(
                $tenant,
                $assignees,
                $projects,
                LostReason::all(),
            );

            $count = $fabricator->fabricate(self::FUNNEL);

            // The tenant has clearly been used, so the onboarding checklist is done.
            $tenant->settings->update(['onboarding' => [
                'branding' => true, 'team' => true, 'project' => true,
                'lead_source' => true, 'first_lead' => true,
            ]]);

            $this->report($count, $result);
        });
    }

    private function seedTeam(Tenant $tenant)
    {
        $manager = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'سارة القحطاني',
            'email' => 'manager@rawasi.test',
            'password' => 'password',
            'role' => Role::Manager,
            'phone' => '+966551000001',
            'is_active' => true,
        ]);

        $sales = collect(DemoContent::SALES_TEAM)->map(fn ($member) => User::create([
            'tenant_id' => $tenant->id,
            'name' => $member[0],
            'email' => $member[1],
            'password' => 'password',
            'role' => Role::Sales,
            'phone' => $member[2],
            'is_active' => true,
        ]));

        return $sales->push($manager);
    }

    private function seedProjects(Tenant $tenant)
    {
        return collect(DemoContent::PROJECTS)->map(fn ($project) => Project::create([
            'tenant_id' => $tenant->id,
            'name' => $project[0],
            'developer_name' => $project[1],
            'location' => $project[2],
            'starting_price' => $project[3],
            'status' => 'active',
        ]));
    }

    private function seedCustomFields(Tenant $tenant): void
    {
        CustomField::create([
            'tenant_id' => $tenant->id,
            'key' => 'rooms_count', 'label' => 'عدد الغرف', 'type' => 'number', 'sort_order' => 1,
        ]);

        CustomField::create([
            'tenant_id' => $tenant->id,
            'key' => 'financing_bank', 'label' => 'الجهة التمويلية', 'type' => 'select',
            'options' => ['الراجحي', 'الأهلي', 'الرياض', 'بنك الإنماء', 'صندوق التنمية العقارية', 'بدون تمويل'],
            'sort_order' => 2,
        ]);
    }

    private function report(int $count, array $result): void
    {
        $this->command?->info("Seeded {$count} demo leads across ".count(self::FUNNEL).' stages.');
        $this->command?->newLine();
        $this->command?->info('Demo webhook endpoint:');
        $this->command?->line('  POST /api/v1/integrations/webhooks/'.$result['webhook_key'].'/leads');
        $this->command?->line('  X-Webhook-Secret: '.$result['webhook_secret']);
    }
}
