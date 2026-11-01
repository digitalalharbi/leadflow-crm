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
use Database\Seeders\Support\LeadFabricator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates one isolated, non-destructive product-evaluation workspace.
 *
 * This is deliberately separate from DemoSeeder and E2ESeeder: both own and
 * replace their fixtures, which is appropriate outside production and never
 * appropriate on a live database. This seeder refuses to delete, refuses to
 * reuse an existing slug, and needs an explicit one-shot gate plus a password.
 */
class ProductionDemoSeeder extends Seeder
{
    public const SLUG = 'mohtam-demo';

    public const EMAIL_DOMAIN = 'leadflow-demo.test';

    private const FUNNEL = [
        LeadStatus::New->value => 18,
        LeadStatus::Contacted->value => 16,
        LeadStatus::Qualified->value => 12,
        LeadStatus::Appointment->value => 6,
        LeadStatus::Won->value => 5,
        LeadStatus::Lost->value => 7,
    ];

    public function run(): void
    {
        if (! app()->environment('production')) {
            throw new RuntimeException('ProductionDemoSeeder only runs in production.');
        }

        if (getenv('PRODUCTION_DEMO_SEED_ENABLED') !== 'true') {
            throw new RuntimeException('Set PRODUCTION_DEMO_SEED_ENABLED=true for this one seed run.');
        }

        $password = (string) getenv('PRODUCTION_DEMO_PASSWORD');

        if (strlen($password) < 16) {
            throw new RuntimeException('PRODUCTION_DEMO_PASSWORD must contain at least 16 characters.');
        }

        $context = app(TenantContext::class);
        $existing = $context->withoutScope(fn () => Tenant::withTrashed()->where('slug', self::SLUG)->exists());

        if ($existing) {
            throw new RuntimeException('The production demo workspace already exists; refusing to replace or merge it.');
        }

        DB::transaction(function () use ($context, $password): void {
            $result = app(TenantProvisioningService::class)->provision([
                'company_name' => 'شركة مهتم التجريبية للتطوير العقاري',
                'subdomain' => self::SLUG,
                'primary_color' => '#0f766e',
                'secondary_color' => '#0f172a',
                'admin_name' => 'مدير مساحة العرض',
                'admin_email' => $this->email('admin'),
                'admin_password' => $password,
                'support_email' => $this->email('support'),
                'assignment_mode' => 'manual',
                'plan' => 'enterprise',
                'trial_days' => 365,
            ]);

            /** @var Tenant $tenant */
            $tenant = $result['tenant'];

            $context->withTenant($tenant, function () use ($tenant, $result, $password): void {
                $users = $this->seedTeam($tenant, $result['admin'], $password);
                $projects = $this->seedProjects($tenant);
                $this->seedCustomFields($tenant);

                $projectsByName = $projects->keyBy('name');
                $users['project_manager']->projects()->sync([$projectsByName['مشروع النخيل السكني']->id]);
                $users['client_viewer']->projects()->sync([$projectsByName['مشروع النخيل السكني']->id]);
                $users['broker_viewer']->projects()->sync([$projectsByName['أبراج الساحل']->id]);

                $assignees = collect([
                    $users['admin'],
                    $users['manager'],
                    $users['sales'],
                    $users['sales_manager'],
                    $users['project_manager'],
                ]);

                $fabricator = new LeadFabricator(
                    $tenant,
                    $assignees,
                    $projects,
                    LostReason::query()->get(),
                    7000000,
                );

                $fabricator->fabricate(self::FUNNEL, 60);
            });
        });

        $this->command?->info('Production demo workspace created without replacing existing data.');
        $this->command?->info('Login accounts use @'.self::EMAIL_DOMAIN.'; the password was supplied out of band.');
    }

    /** @return array<string, User> */
    private function seedTeam(Tenant $tenant, User $admin, string $password): array
    {
        $users = ['admin' => $admin];

        foreach ([
            'manager' => ['مدير الفريق', Role::Manager],
            'sales' => ['موظف المبيعات', Role::Sales],
            'marketing_manager' => ['مدير التسويق', Role::MarketingManager],
            'sales_manager' => ['مدير المبيعات', Role::SalesManager],
            'project_manager' => ['مدير المشروع', Role::ProjectManager],
            'finance' => ['المسؤول المالي', Role::Finance],
            'client_viewer' => ['عميل المشروع', Role::ClientViewer],
            'broker_viewer' => ['الوسيط العقاري', Role::BrokerViewer],
        ] as $key => [$name, $role]) {
            $users[$key] = User::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'email' => $this->email(str_replace('_', '-', $key)),
                'password' => $password,
                'role' => $role,
                'is_active' => true,
                'locale' => 'ar',
            ]);
        }

        return $users;
    }

    /** @return Collection<int, Project> */
    private function seedProjects(Tenant $tenant): Collection
    {
        return collect([
            ['مشروع النخيل السكني', 'مهتم للتطوير', 'الرياض', 950000],
            ['فلل الياسمين', 'مهتم للتطوير', 'الرياض', 2250000],
            ['أبراج الساحل', 'مهتم للتطوير', 'جدة', 1150000],
        ])->map(fn (array $project) => Project::create([
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
            'key' => 'preferred_unit',
            'label' => 'نوع الوحدة المفضل',
            'type' => 'select',
            'options' => ['شقة', 'فيلا', 'دوبلكس', 'تاون هاوس'],
            'sort_order' => 1,
        ]);

        CustomField::create([
            'tenant_id' => $tenant->id,
            'key' => 'financing_bank',
            'label' => 'جهة التمويل',
            'type' => 'text',
            'sort_order' => 2,
        ]);
    }

    private function email(string $local): string
    {
        return $local.'@'.self::EMAIL_DOMAIN;
    }
}
