<?php

namespace Database\Seeders;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\Role;
use App\Models\CustomField;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadNote;
use App\Models\LeadSourceEvent;
use App\Models\LostReason;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Builds a staging/demo environment that looks like a real customer account —
 * believable Arabic names, real-shaped campaigns, a funnel with history.
 *
 * Local and staging only. Never run against production.
 */
class DemoSeeder extends Seeder
{
    private const FIRST_NAMES = [
        'محمد', 'عبدالله', 'أحمد', 'فهد', 'سعود', 'خالد', 'نواف', 'بندر', 'ماجد', 'تركي',
        'عمر', 'سلطان', 'ياسر', 'راكان', 'مشعل', 'نورة', 'سارة', 'ريم', 'لمى', 'هند',
        'منى', 'أسماء', 'دانة', 'شهد', 'الجوهرة', 'عبدالعزيز', 'مشاري', 'فيصل', 'وليد', 'زياد',
    ];

    private const LAST_NAMES = [
        'الحربي', 'القحطاني', 'الشمري', 'العتيبي', 'الدوسري', 'الغامدي', 'الزهراني',
        'المطيري', 'السبيعي', 'الشهري', 'البقمي', 'الرشيدي', 'العنزي', 'الجهني', 'المالكي',
    ];

    private const CITIES = ['الرياض', 'جدة', 'الدمام', 'الخبر', 'مكة المكرمة', 'المدينة المنورة', 'الطائف', 'بريدة'];

    private const CAMPAIGNS = [
        ['حملة إطلاق مشروع النرجس', 'meta', 'انستقرام - ستوري', 'فيديو جولة المشروع'],
        ['حملة تمويل عقاري - الرياض', 'meta', 'فيسبوك - خبر', 'كاروسيل الوحدات'],
        ['حملة سناب - شقق التحلية', 'snapchat', 'سناب - ستوري', 'إعلان فيديو عمودي'],
        ['حملة تيك توك - فلل الياسمين', 'tiktok', 'تيك توك - For You', 'جولة 360'],
        ['بحث جوجل - شقق للبيع الرياض', 'google_ads', 'بحث - كلمات شراء', 'نص إعلاني A'],
        ['حملة لينكدإن - مستثمرين', 'linkedin', 'لينكدإن - فيد', 'عرض الاستثمار'],
    ];

    private const PROPERTY_TYPES = ['شقة', 'فيلا', 'دوبلكس', 'أرض سكنية', 'تاون هاوس'];

    private const TIMELINES = ['immediate', 'within_month', 'within_3_months', 'within_6_months', 'exploring'];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->error('DemoSeeder refuses to run in production.');

            return;
        }

        $context = app(TenantContext::class);
        $provisioning = app(TenantProvisioningService::class);

        // Idempotent: re-running the seeder replaces the demo tenant cleanly.
        $existing = $context->withoutScope(fn () => Tenant::where('slug', 'rawasi')->first());

        if ($existing) {
            $context->withoutScope(fn () => $existing->forceDelete());
        }

        $result = $provisioning->provision([
            'company_name' => 'شركة رواسي للتطوير العقاري',
            'system_name' => 'رواسي CRM',
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
            $this->command->info('Seeding demo tenant: '.$tenant->name);

            $manager = User::create([
                'tenant_id' => $tenant->id,
                'name' => 'سارة القحطاني',
                'email' => 'manager@rawasi.test',
                'password' => 'password',
                'role' => Role::Manager,
                'phone' => '+966551000001',
                'is_active' => true,
            ]);

            $salesNames = [
                ['فهد العتيبي', 'fahad@rawasi.test', '+966551000002'],
                ['نورة الشمري', 'noura@rawasi.test', '+966551000003'],
                ['خالد الدوسري', 'khalid@rawasi.test', '+966551000004'],
                ['ريم الغامدي', 'reem@rawasi.test', '+966551000005'],
                ['ماجد الزهراني', 'majed@rawasi.test', '+966551000006'],
            ];

            $sales = collect($salesNames)->map(fn ($s) => User::create([
                'tenant_id' => $tenant->id,
                'name' => $s[0],
                'email' => $s[1],
                'password' => 'password',
                'role' => Role::Sales,
                'phone' => $s[2],
                'is_active' => true,
            ]));

            $projects = collect([
                ['مشروع النرجس ريزيدنس', 'رواسي للتطوير', 'حي النرجس - الرياض', 1250000],
                ['فلل الياسمين', 'رواسي للتطوير', 'حي الياسمين - الرياض', 2400000],
                ['أبراج التحلية', 'رواسي للتطوير', 'شارع التحلية - جدة', 980000],
            ])->map(fn ($p) => Project::create([
                'name' => $p[0],
                'developer_name' => $p[1],
                'location' => $p[2],
                'starting_price' => $p[3],
                'status' => 'active',
            ]));

            CustomField::create([
                'key' => 'rooms_count', 'label' => 'عدد الغرف', 'type' => 'number', 'sort_order' => 1,
            ]);
            CustomField::create([
                'key' => 'financing_bank', 'label' => 'الجهة التمويلية', 'type' => 'select',
                'options' => ['الراجحي', 'الأهلي', 'الرياض', 'بنك الإنماء', 'صندوق التنمية العقارية', 'بدون تمويل'],
                'sort_order' => 2,
            ]);

            $lostReasons = LostReason::all();
            $assignees = $sales->push($manager);

            $this->seedLeads($tenant, $assignees, $projects, $lostReasons);

            // The tenant has been "used", so the onboarding checklist reflects that.
            $tenant->settings->update(['onboarding' => [
                'branding' => true, 'team' => true, 'project' => true,
                'lead_source' => true, 'first_lead' => true,
            ]]);

            $this->command->newLine();
            $this->command->info('Demo webhook endpoint:');
            $this->command->line('  POST /api/v1/integrations/webhooks/'.$result['webhook_key'].'/leads');
            $this->command->line('  X-Webhook-Secret: '.$result['webhook_secret']);
        });
    }

    private function seedLeads($tenant, $assignees, $projects, $lostReasons): void
    {
        // A realistic funnel shape rather than a uniform spread.
        $distribution = [
            LeadStatus::New->value => 22,
            LeadStatus::Contacted->value => 18,
            LeadStatus::Qualified->value => 14,
            LeadStatus::Appointment->value => 9,
            LeadStatus::Won->value => 8,
            LeadStatus::Lost->value => 14,
        ];

        $phoneCounter = 1000;
        $created = 0;

        foreach ($distribution as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                $first = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)];
                $last = self::LAST_NAMES[array_rand(self::LAST_NAMES)];
                $campaign = self::CAMPAIGNS[array_rand(self::CAMPAIGNS)];
                $project = $projects->random();
                $assignee = $status === LeadStatus::New->value && $i % 3 === 0 ? null : $assignees->random();

                $createdAt = now()->subDays(rand(0, 45))->subHours(rand(0, 23));
                $phone = '+96655'.str_pad((string) (++$phoneCounter), 7, '0', STR_PAD_LEFT);

                $score = match ($status) {
                    LeadStatus::Won->value => rand(78, 100),
                    LeadStatus::Appointment->value => rand(70, 95),
                    LeadStatus::Qualified->value => rand(55, 85),
                    LeadStatus::Contacted->value => rand(35, 70),
                    LeadStatus::Lost->value => rand(10, 55),
                    default => rand(20, 65),
                };

                $budgetMin = [500000, 800000, 1000000, 1500000, 2000000][array_rand([0, 1, 2, 3, 4])];

                $lead = Lead::create([
                    'assigned_to' => $assignee?->id,
                    'project_id' => $project->id,
                    'first_name' => $first,
                    'last_name' => $last,
                    'full_name' => $first.' '.$last,
                    'phone' => $phone,
                    'phone_normalized' => $phone,
                    'email' => rand(0, 1) ? Str::lower(Str::random(7)).'@example.com' : null,
                    'city' => self::CITIES[array_rand(self::CITIES)],
                    'platform' => $campaign[1],
                    'source' => $campaign[1] === 'google_ads' ? 'search' : 'paid_social',
                    'campaign_name' => $campaign[0],
                    'campaign_id' => '23859'.rand(100000, 999999),
                    'adset_name' => $campaign[2],
                    'ad_name' => $campaign[3],
                    'form_name' => 'نموذج التسجيل - '.$project->name,
                    'status' => $status,
                    'temperature' => LeadTemperature::fromScore($score)->value,
                    'score' => $score,
                    'budget_min' => $budgetMin,
                    'budget_max' => $budgetMin * 1.4,
                    'purchase_purpose' => rand(0, 1) ? 'residence' : 'investment',
                    'payment_method' => rand(0, 2) === 0 ? 'cash' : 'financing',
                    'preferred_property_type' => self::PROPERTY_TYPES[array_rand(self::PROPERTY_TYPES)],
                    'preferred_location' => $project->location,
                    'purchase_timeline' => self::TIMELINES[array_rand(self::TIMELINES)],
                    'provider' => 'generic_webhook',
                    'provider_lead_id' => 'demo-'.Str::random(10),
                    'assigned_at' => $assignee ? $createdAt->copy()->addMinutes(rand(1, 120)) : null,
                    'last_contact_at' => in_array($status, ['new'], true) ? null : $createdAt->copy()->addDays(rand(1, 3)),
                    'won_at' => $status === LeadStatus::Won->value ? $createdAt->copy()->addDays(rand(5, 20)) : null,
                    'lost_at' => $status === LeadStatus::Lost->value ? $createdAt->copy()->addDays(rand(3, 15)) : null,
                    'lost_reason_id' => $status === LeadStatus::Lost->value ? $lostReasons->random()->id : null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);

                LeadSourceEvent::create([
                    'lead_id' => $lead->id,
                    'provider' => 'generic_webhook',
                    'platform' => $campaign[1],
                    'campaign_name' => $campaign[0],
                    'adset_name' => $campaign[2],
                    'ad_name' => $campaign[3],
                    'received_at' => $createdAt,
                ]);

                $this->seedTimeline($tenant, $lead, $assignee, $status, $createdAt);
                $this->seedFollowUp($tenant, $lead, $assignee, $status, $createdAt);

                $created++;
            }
        }

        $this->command->info("Seeded {$created} demo leads.");
    }

    private function seedTimeline($tenant, Lead $lead, ?User $assignee, string $status, $createdAt): void
    {
        $log = function (ActivityType $type, $at, array $meta = [], ?User $user = null) use ($lead) {
            LeadActivity::create([
                'lead_id' => $lead->id,
                'user_id' => $user?->id,
                'type' => $type,
                'meta' => $meta ?: null,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        };

        $log(ActivityType::Created, $createdAt, ['provider' => 'generic_webhook']);

        if ($assignee) {
            $log(ActivityType::Assigned, $createdAt->copy()->addMinutes(20), ['assigned_to_name' => $assignee->name]);
        }

        if ($status === LeadStatus::New->value) {
            return;
        }

        $log(ActivityType::Called, $createdAt->copy()->addDays(1), [], $assignee);
        $log(ActivityType::StatusChanged, $createdAt->copy()->addDays(1), ['from' => 'new', 'to' => 'contacted'], $assignee);

        if (rand(0, 1)) {
            $log(ActivityType::WhatsappClicked, $createdAt->copy()->addDays(1)->addHours(2), [], $assignee);
        }

        $noteBodies = [
            'العميل مهتم بالوحدات ذات الثلاث غرف، طلب جدول الدفعات.',
            'تم إرسال بروشور المشروع عبر واتساب. سيعاود الاتصال بعد مراجعة الأسرة.',
            'يحتاج تمويل عقاري، تم توجيهه لقسم التمويل.',
            'الميزانية أقل من المعروض، تم عرض خيار بديل في مشروع آخر.',
            'مهتم جداً وطلب زيارة الموقع نهاية الأسبوع.',
        ];

        if ($assignee && rand(0, 2) > 0) {
            LeadNote::create([
                'lead_id' => $lead->id,
                'user_id' => $assignee->id,
                'body' => $noteBodies[array_rand($noteBodies)],
                'created_at' => $createdAt->copy()->addDays(2),
                'updated_at' => $createdAt->copy()->addDays(2),
            ]);

            $log(ActivityType::NoteAdded, $createdAt->copy()->addDays(2), [], $assignee);
        }

        if (in_array($status, ['qualified', 'appointment', 'won'], true)) {
            $log(ActivityType::StatusChanged, $createdAt->copy()->addDays(3), ['from' => 'contacted', 'to' => 'qualified'], $assignee);
        }

        if (in_array($status, ['appointment', 'won'], true)) {
            $log(ActivityType::AppointmentCreated, $createdAt->copy()->addDays(5), [], $assignee);
        }

        if ($status === LeadStatus::Won->value) {
            $log(ActivityType::Won, $lead->won_at, [], $assignee);
        }

        if ($status === LeadStatus::Lost->value) {
            $log(ActivityType::Lost, $lead->lost_at, ['reason' => $lead->lostReason?->label], $assignee);
        }
    }

    /** Spread follow-ups over overdue / today / upcoming so all three buckets are populated. */
    private function seedFollowUp($tenant, Lead $lead, ?User $assignee, string $status, $createdAt): void
    {
        if (! $assignee || in_array($status, ['won', 'lost'], true)) {
            return;
        }

        $roll = rand(1, 10);

        $dueAt = match (true) {
            $roll <= 3 => now()->subDays(rand(1, 6))->setTime(rand(9, 17), 0),   // overdue
            $roll <= 6 => now()->setTime(rand(9, 18), [0, 30][rand(0, 1)]),       // today
            $roll <= 9 => now()->addDays(rand(1, 10))->setTime(rand(9, 17), 0),   // upcoming
            default => null,
        };

        if ($dueAt === null) {
            return;
        }

        $notes = [
            'الاتصال لتأكيد موعد الزيارة',
            'متابعة بخصوص جدول الدفعات',
            'إرسال عرض السعر النهائي',
            'التأكد من حالة طلب التمويل',
            'مكالمة متابعة بعد الزيارة',
        ];

        FollowUp::create([
            'lead_id' => $lead->id,
            'assigned_to' => $assignee->id,
            'created_by' => $assignee->id,
            'due_at' => $dueAt,
            'note' => $notes[array_rand($notes)],
            'status' => FollowUp::PENDING,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $lead->forceFill(['next_follow_up_at' => $dueAt])->saveQuietly();
    }
}
