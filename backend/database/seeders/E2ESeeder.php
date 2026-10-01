<?php

namespace Database\Seeders;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\Role;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LostReason;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;

/**
 * A known, fixed starting state for the Playwright suite.
 *
 * Deliberately NOT the demo seeder: E2E assertions need exact counts and stable
 * names, and a dataset tuned to look good in a sales demo is the wrong thing to
 * assert against. Every value here is fixed — no randomness — so a failure is
 * always a real regression rather than a reshuffled fixture.
 *
 * Two tenants exist so cross-tenant isolation can be proven through the browser.
 */
class E2ESeeder extends Seeder
{
    public const PASSWORD = 'Password123!';

    public const ALPHA_SLUG = 'e2e-alpha';

    public const BETA_SLUG = 'e2e-beta';

    /** Leads addressed by name in the tests. Keep these unique and searchable. */
    public const LEAD_SEARCH = 'بدرية الفريدة';

    public const LEAD_WORKFLOW = 'وليد سير العمل';

    public const LEAD_LOST = 'سعد الفقدان';

    public const LEAD_DRAG = 'راكان السحب';

    public const LEAD_ACTIVITY = 'هيفاء الأنشطة';

    public const LEAD_ASSIGN = 'بشاير الإسناد';

    public const BETA_SECRET_LEAD = 'سر بيتا المحجوب';

    /** Generic filler, enough to force a second page at 25 per page. */
    public const FILLER_COUNT = 30;

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('E2ESeeder refuses to run in production.');

            return;
        }

        $context = app(TenantContext::class);

        foreach ([self::ALPHA_SLUG, self::BETA_SLUG] as $slug) {
            $existing = $context->withoutScope(fn () => Tenant::where('slug', $slug)->first());
            $existing && $context->withoutScope(fn () => $existing->forceDelete());
        }

        $this->seedAlpha($context);
        $this->seedBeta($context);

        $this->command?->info('E2E fixtures ready (alpha + beta).');
    }

    private function seedAlpha(TenantContext $context): void
    {
        $result = app(TenantProvisioningService::class)->provision([
            'company_name' => 'شركة ألفا للتطوير العقاري',
            'system_name' => 'ألفا CRM',
            'subdomain' => self::ALPHA_SLUG,
            'primary_color' => '#0f766e',
            'secondary_color' => '#0f172a',
            'admin_name' => 'مدير ألفا',
            'admin_email' => 'admin@e2e.test',
            'admin_password' => self::PASSWORD,
            'support_email' => 'support@alpha-e2e.test',
            // Manual, so assignment tests control who owns what. A round-robin
            // default would make every created lead land somewhere unpredictable.
            'assignment_mode' => 'manual',
        ]);

        $tenant = $result['tenant'];

        $context->withTenant($tenant, function () use ($tenant) {
            $manager = $this->user($tenant, 'مدير مبيعات ألفا', 'manager@e2e.test', Role::Manager);
            $rep1 = $this->user($tenant, 'مندوب أول', 'rep1@e2e.test', Role::Sales);
            $rep2 = $this->user($tenant, 'مندوب ثاني', 'rep2@e2e.test', Role::Sales);
            $admin = User::where('email', 'admin@e2e.test')->first();

            $projects = collect([
                ['مشروع الاختبار الأول', 'حي النرجس - الرياض', 1_200_000],
                ['مشروع الاختبار الثاني', 'حي الياسمين - الرياض', 2_100_000],
                ['مشروع الاختبار الثالث', 'الكورنيش - الخبر', 850_000],
            ])->map(fn ($p) => Project::create([
                'tenant_id' => $tenant->id,
                'name' => $p[0],
                'developer_name' => 'ألفا للتطوير',
                'location' => $p[1],
                'starting_price' => $p[2],
                'status' => 'active',
            ]));

            $firstProject = $projects->first();

            // --- Named leads the tests drive directly -----------------------
            $this->lead($tenant, self::LEAD_SEARCH, '+966500000001', [
                'assigned_to' => $admin->id, 'project_id' => $firstProject->id,
                'status' => LeadStatus::New->value, 'temperature' => LeadTemperature::Hot->value,
                'score' => 85, 'platform' => 'meta', 'campaign_name' => 'حملة الاختبار',
                'city' => 'الرياض',
            ]);

            $this->lead($tenant, self::LEAD_WORKFLOW, '+966500000002', [
                'assigned_to' => $admin->id, 'project_id' => $firstProject->id,
                'status' => LeadStatus::New->value, 'temperature' => LeadTemperature::Warm->value,
                'score' => 50, 'platform' => 'tiktok',
            ]);

            $this->lead($tenant, self::LEAD_LOST, '+966500000003', [
                'assigned_to' => $admin->id, 'status' => LeadStatus::Contacted->value,
                'temperature' => LeadTemperature::Cold->value, 'score' => 30, 'platform' => 'snapchat',
            ]);

            $this->lead($tenant, self::LEAD_DRAG, '+966500000004', [
                'assigned_to' => $admin->id, 'status' => LeadStatus::New->value,
                'temperature' => LeadTemperature::Warm->value, 'score' => 55, 'platform' => 'google_ads',
            ]);

            $this->lead($tenant, self::LEAD_ACTIVITY, '+966500000005', [
                'assigned_to' => $admin->id, 'status' => LeadStatus::New->value,
                'temperature' => LeadTemperature::Hot->value, 'score' => 75, 'platform' => 'meta',
            ]);

            // Unassigned on purpose: the assignment test gives it an owner.
            $this->lead($tenant, self::LEAD_ASSIGN, '+966500000006', [
                'assigned_to' => null, 'status' => LeadStatus::New->value,
                'temperature' => LeadTemperature::Warm->value, 'score' => 45, 'platform' => 'website',
            ]);

            // --- Rep-owned leads, for the "sees only their own" test ---------
            foreach (range(1, 3) as $i) {
                $this->lead($tenant, "عميل المندوب الأول {$i}", '+96650010000'.$i, [
                    'assigned_to' => $rep1->id, 'status' => LeadStatus::Contacted->value,
                    'temperature' => LeadTemperature::Warm->value, 'score' => 50, 'platform' => 'meta',
                ]);
            }

            foreach (range(1, 2) as $i) {
                $this->lead($tenant, "عميل المندوب الثاني {$i}", '+96650020000'.$i, [
                    'assigned_to' => $rep2->id, 'status' => LeadStatus::Qualified->value,
                    'temperature' => LeadTemperature::Hot->value, 'score' => 72, 'platform' => 'tiktok',
                ]);
            }

            // --- Filler, so pagination has a second page ---------------------
            $statuses = [
                LeadStatus::New->value, LeadStatus::Contacted->value, LeadStatus::Qualified->value,
                LeadStatus::Appointment->value, LeadStatus::Won->value, LeadStatus::Lost->value,
            ];
            $platforms = ['meta', 'tiktok', 'snapchat', 'google_ads', 'linkedin'];
            $lostReason = LostReason::first();

            foreach (range(1, self::FILLER_COUNT) as $i) {
                $status = $statuses[$i % count($statuses)];

                $this->lead($tenant, 'عميل تجريبي '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), '+9665003'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), [
                    'assigned_to' => [$admin->id, $manager->id, $rep1->id][$i % 3],
                    'project_id' => $projects[$i % 3]->id,
                    'status' => $status,
                    'temperature' => [LeadTemperature::Hot->value, LeadTemperature::Warm->value, LeadTemperature::Cold->value][$i % 3],
                    'score' => 20 + ($i % 70),
                    'platform' => $platforms[$i % count($platforms)],
                    'campaign_name' => 'حملة رقم '.(($i % 4) + 1),
                    'lost_reason_id' => $status === LeadStatus::Lost->value ? $lostReason?->id : null,
                    'lost_at' => $status === LeadStatus::Lost->value ? now()->subDays(2) : null,
                    'won_at' => $status === LeadStatus::Won->value ? now()->subDays(3) : null,
                ]);
            }

            // --- Follow-ups: exactly two per bucket, owned by the admin ------
            $this->followUps($tenant, $admin);
        });
    }

    private function seedBeta(TenantContext $context): void
    {
        $result = app(TenantProvisioningService::class)->provision([
            'company_name' => 'شركة بيتا القابضة',
            'system_name' => 'بيتا CRM',
            'subdomain' => self::BETA_SLUG,
            // Visibly different identity, so white-label assertions are unambiguous.
            'primary_color' => '#b91c1c',
            'secondary_color' => '#450a0a',
            'logo_url' => 'https://placehold.co/96x96/b91c1c/ffffff/png?text=B',
            'admin_name' => 'مدير بيتا',
            'admin_email' => 'admin@beta-e2e.test',
            'admin_password' => self::PASSWORD,
            'support_email' => 'support@beta-e2e.test',
        ]);

        $tenant = $result['tenant'];

        $context->withTenant($tenant, function () use ($tenant) {
            $admin = User::where('email', 'admin@beta-e2e.test')->first();

            $this->lead($tenant, self::BETA_SECRET_LEAD, '+966509999999', [
                'assigned_to' => $admin->id,
                'status' => LeadStatus::Qualified->value,
                'temperature' => LeadTemperature::Hot->value,
                'score' => 90,
                'platform' => 'meta',
                'campaign_name' => 'حملة بيتا السرية',
            ]);
        });
    }

    private function user(Tenant $tenant, string $name, string $email, Role $role): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function lead(Tenant $tenant, string $name, string $phone, array $attributes = []): Lead
    {
        $parts = explode(' ', $name, 2);

        $lead = Lead::create(array_merge([
            'tenant_id' => $tenant->id,
            'first_name' => $parts[0],
            'last_name' => $parts[1] ?? null,
            'full_name' => $name,
            'phone' => $phone,
            'phone_normalized' => $phone,
            'city' => 'الرياض',
            'provider' => 'generic_webhook',
            'source' => 'paid_social',
            'budget_min' => 800_000,
            'budget_max' => 1_200_000,
            'preferred_property_type' => 'شقة',
            'purchase_timeline' => 'within_month',
            'payment_method' => 'financing',
        ], $attributes));

        LeadActivity::create([
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'type' => ActivityType::Created,
            'meta' => ['provider' => 'generic_webhook'],
        ]);

        return $lead;
    }

    /** Two overdue, two due today, two upcoming — exact, so counts can be asserted. */
    private function followUps(Tenant $tenant, User $owner): void
    {
        $targets = Lead::whereIn('full_name', [
            'عميل تجريبي 01', 'عميل تجريبي 02', 'عميل تجريبي 03',
            'عميل تجريبي 04', 'عميل تجريبي 05', 'عميل تجريبي 06',
        ])->orderBy('full_name')->get();

        $schedule = [
            now()->subDays(3)->setTime(10, 0),
            now()->subDays(1)->setTime(11, 0),
            now()->setTime(9, 0),
            now()->setTime(16, 0),
            now()->addDays(2)->setTime(10, 0),
            now()->addDays(5)->setTime(14, 0),
        ];

        foreach ($targets as $i => $lead) {
            if (! isset($schedule[$i])) {
                break;
            }

            FollowUp::create([
                'tenant_id' => $tenant->id,
                'lead_id' => $lead->id,
                'assigned_to' => $owner->id,
                'created_by' => $owner->id,
                'due_at' => $schedule[$i],
                'note' => 'متابعة اختبار رقم '.($i + 1),
                'status' => FollowUp::PENDING,
            ]);

            $lead->forceFill(['assigned_to' => $owner->id, 'next_follow_up_at' => $schedule[$i]])->saveQuietly();
        }
    }
}
