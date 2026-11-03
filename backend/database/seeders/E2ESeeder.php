<?php

namespace Database\Seeders;

use App\Enums\ActivityType;
use App\Enums\IntegrationProvider;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Role;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\FollowUp;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadMessage;
use App\Models\LeadSourceEvent;
use App\Models\LostReason;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\RepositoryActivity;
use App\Models\RepositoryDeployment;
use App\Models\RepositoryPullRequest;
use App\Models\RepositoryRelease;
use App\Models\RepositoryWorkflowRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

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

    /**
     * Created by the onboarding spec rather than here, but removed here so that
     * run starts from nothing. A fixed slug beats a timestamped one: the spec
     * stays deterministic and leaves no debris behind.
     */
    public const ONBOARDING_SLUG = 'e2e-onboarding';

    /** Leads addressed by name in the tests. Keep these unique and searchable. */
    public const LEAD_SEARCH = 'بدرية الفريدة';

    public const LEAD_WORKFLOW = 'وليد سير العمل';

    public const LEAD_LOST = 'سعد الفقدان';

    public const LEAD_DRAG = 'راكان السحب';

    public const LEAD_ACTIVITY = 'هيفاء الأنشطة';

    public const LEAD_ASSIGN = 'بشاير الإسناد';

    public const BETA_SECRET_LEAD = 'سر المدار المحجوب';

    /** A lead with a conversation already on it, and one with none. */
    public const LEAD_CHAT = 'غادة المحادثة';

    public const LEAD_QUALIFY = 'ماجد التأهيل';

    /** Alpha's WhatsApp number, so webhook fixtures can target it. */
    public const ALPHA_PHONE_NUMBER_ID = '100000000000001';

    public const ALPHA_APP_SECRET = 'e2e-alpha-app-secret-value';

    public const BETA_PHONE_NUMBER_ID = '100000000000002';

    /** Generic filler, enough to force a second page at 25 per page. */
    public const FILLER_COUNT = 30;

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('E2ESeeder refuses to run in production.');

            return;
        }

        $context = app(TenantContext::class);

        foreach ([self::ALPHA_SLUG, self::BETA_SLUG, self::ONBOARDING_SLUG] as $slug) {
            $existing = $context->withoutScope(fn () => Tenant::where('slug', $slug)->first());
            $existing && $context->withoutScope(fn () => $existing->forceDelete());
        }

        $this->seedAlpha($context);
        $this->seedBeta($context);

        $this->command?->info('E2E fixtures ready (alpha + beta).');

        // A new company starts with its journey off. These fixtures are companies that reviewed it and turned it on.
        AutomationRule::withoutGlobalScopes()->whereNotNull('template_key')->update(['is_enabled' => true]);
    }

    private function seedAlpha(TenantContext $context): void
    {
        $result = app(TenantProvisioningService::class)->provision([
            'company_name' => 'شركة الريادة للتطوير العقاري',
            // No system_name: this workspace has not renamed the product, so it shows
            // مهتم — which is what the identity specs assert. The other tenant below
            // is the one that *has* renamed it.
            'subdomain' => self::ALPHA_SLUG,
            'primary_color' => '#0f766e',
            'secondary_color' => '#0f172a',
            'admin_name' => 'مدير الريادة',
            'admin_email' => 'admin@e2e.test',
            'admin_password' => self::PASSWORD,
            'support_email' => 'support@riyada.test',
            // Manual, so assignment tests control who owns what. A round-robin
            // default would make every created lead land somewhere unpredictable.
            'assignment_mode' => 'manual',
        ]);

        $tenant = $result['tenant'];

        $context->withTenant($tenant, function () use ($tenant) {
            $manager = $this->user($tenant, 'مدير مبيعات الريادة', 'manager@e2e.test', Role::Manager);
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
                'developer_name' => 'الريادة للتطوير',
                'location' => $p[1],
                'starting_price' => $p[2],
                'status' => 'active',
            ]));

            $firstProject = $projects->first();

            // One of each specialised role. The scoped ones are given a project: the first (project manager,
            // client) or the second (broker) — so the scope specs have something they must not see.
            $this->user($tenant, 'مدير التسويق', 'marketing@e2e.test', Role::MarketingManager);
            $this->user($tenant, 'مدير المبيعات', 'salesmgr@e2e.test', Role::SalesManager);
            $this->user($tenant, 'المالية', 'finance@e2e.test', Role::Finance);
            $this->user($tenant, 'مدير المشروع', 'projectmgr@e2e.test', Role::ProjectManager)->projects()->sync([$firstProject->id]);
            $this->user($tenant, 'عميل المشروع', 'client@e2e.test', Role::ClientViewer)->projects()->sync([$firstProject->id]);
            $this->user($tenant, 'الوسيط', 'broker@e2e.test', Role::BrokerViewer)->projects()->sync([$projects[1]->id]);

            $this->seedDevelopment($tenant, $firstProject);

            /*
             * WhatsApp connected in demo mode: a phone number id so webhook
             * deliveries route here, an app secret so signatures are real, and
             * no access token — which keeps the mock provider in play.
             */
            Integration::create([
                'tenant_id' => $tenant->id,
                'provider' => IntegrationProvider::Whatsapp,
                'name' => 'WhatsApp Business',
                'status' => Integration::CONFIGURATION_REQUIRED,
                'config' => ['phone_number_id' => self::ALPHA_PHONE_NUMBER_ID, 'business_account_id' => '900900900'],
                'credentials' => ['verify_token' => 'e2e-alpha-verify-token', 'app_secret' => self::ALPHA_APP_SECRET],
                'is_active' => true,
            ]);

            // --- Named leads the tests drive directly -----------------------
            $chat = $this->lead($tenant, self::LEAD_CHAT, '+966500000011', [
                'assigned_to' => $admin->id, 'project_id' => $firstProject->id,
                'status' => LeadStatus::Contacted->value, 'platform' => 'meta',
                'campaign_name' => 'حملة الاختبار',
                // The one lead the email specs write to. `.test` never resolves,
                // so nothing a test sends can reach a person.
                'email' => 'ghada.chat@customer.test',
            ]);

            // One outbound, one reply: enough for the conversation to render
            // both bubble shapes and a delivery state.
            LeadMessage::create([
                'tenant_id' => $tenant->id, 'lead_id' => $chat->id, 'user_id' => $admin->id,
                'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Whatsapp,
                'provider' => 'mock', 'provider_message_id' => 'wamid.e2e.seed.out.1',
                'message_type' => 'text', 'body' => 'مرحبًا غادة، معك فريق الريادة بخصوص اهتمامك بالمشروع.',
                'status' => MessageStatus::Delivered,
                'sent_at' => now()->subHours(3), 'delivered_at' => now()->subHours(3),
            ]);

            LeadMessage::create([
                'tenant_id' => $tenant->id, 'lead_id' => $chat->id,
                'direction' => MessageDirection::Inbound, 'channel' => MessageChannel::Whatsapp,
                'provider' => 'whatsapp_cloud', 'provider_message_id' => 'wamid.e2e.seed.in.1',
                'message_type' => 'text', 'body' => 'نعم مهتم، أرسل لي التفاصيل.',
                'status' => MessageStatus::Received,
            ]);

            // Deliberately unqualified, with no answers filled in, so the
            // qualification test starts from a known empty state.
            $this->lead($tenant, self::LEAD_QUALIFY, '+966500000012', [
                'assigned_to' => $admin->id, 'project_id' => $firstProject->id,
                'status' => LeadStatus::New->value, 'platform' => 'meta',
                'campaign_name' => 'حملة الاختبار',
                'budget_min' => null, 'budget_max' => null,
                'preferred_property_type' => null, 'purchase_timeline' => null,
                'payment_method' => null,
            ]);

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

            $this->seedAutomationHistory($tenant, $admin);

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

                /*
                 * A closed lead is backdated far enough that its whole journey
                 * fits before now.
                 *
                 * These used to be absolute — `won_at = now()->subDays(3)` on
                 * a lead created this second — so every won lead in the
                 * fixtures was closed three days *before* it arrived. Nothing
                 * failed: the velocity chart quietly drops any step whose end
                 * precedes its start, so "من التأهيل إلى البيع" simply showed
                 * a dash in every run, and the two steps it was written to
                 * measure were never exercised at all.
                 */
                $won = $status === LeadStatus::Won->value;
                $lost = $status === LeadStatus::Lost->value;

                /*
                 * Arrivals are spread, and the closed ones straddle two windows.
                 *
                 * Every filler lead used to be stamped at the instant of
                 * seeding, so the thirty-day lead-flow chart was a flat line
                 * with one spike at the right-hand edge — a correct chart of
                 * fixture data that looks nothing like the data the screen was
                 * designed for.
                 *
                 * The closed ones then have to satisfy two screens that want
                 * opposite things. The dashboard measures the last thirty days;
                 * «عوامل الإغلاق» counts only leads old enough to have finished
                 * converting. Aging all of them emptied the velocity chart, and
                 * aging none of them emptied the conversion analysis — so half
                 * sit in each, and each closed lead is still backdated far
                 * enough that its whole journey fits before now.
                 */
                $mature = intdiv($i, 6) % 2 === 1;

                $createdAt = match (true) {
                    $won => now()->subDays(($mature ? 40 : 12) + ($i % 9)),
                    $lost => now()->subDays(($mature ? 34 : 6) + ($i % 5)),
                    default => now()->subDays($i % 21),
                };

                // Only the statuses that genuinely passed through qualification.
                // `lost` is left out on purpose: a lead can be lost before
                // anybody qualified it, and claiming otherwise would inflate
                // the qualification rate in the fixtures the screens are read
                // against.
                $qualifiedAt = null;

                if (in_array($status, [
                    LeadStatus::Qualified->value, LeadStatus::Appointment->value, LeadStatus::Won->value,
                ], true)) {
                    /*
                     * Six hours is the shape we want, bounded by how long the
                     * lead has actually existed.
                     *
                     * `now()->subDays($i % 21)` is this instant for every
                     * twenty-first lead, and a flat `addHours(6)` then stamped
                     * the qualification six hours into the future. The journey
                     * card computes the waiting time from the last milestone,
                     * so a lead sitting at "appointment" since this morning
                     * reported that it had been waiting "less than a minute" —
                     * the component clamping a negative number, correctly, over
                     * data that should never have said that.
                     *
                     * Fixtures are read as the product, so they have to describe
                     * a past that happened.
                     */
                    // No floor: a lead seeded as arriving at this instant is
                    // qualified at that same instant, because the alternative
                    // is a minute that has not happened yet.
                    $minutes = min(6 * 60, max(0, intdiv((int) $createdAt->diffInMinutes(now()), 2)));
                    $qualifiedAt = $createdAt->copy()->addMinutes($minutes);
                }

                $filler = $this->lead($tenant, 'عميل تجريبي '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), '+9665003'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), [
                    'assigned_to' => [$admin->id, $manager->id][$i % 2],
                    'project_id' => $projects[$i % 3]->id,
                    'status' => $status,
                    'temperature' => [LeadTemperature::Hot->value, LeadTemperature::Warm->value, LeadTemperature::Cold->value][$i % 3],
                    'score' => 20 + ($i % 70),
                    'platform' => $platforms[$i % count($platforms)],
                    'campaign_name' => 'حملة رقم '.(($i % 4) + 1),
                    'created_at' => $createdAt,
                    'qualified_at' => $qualifiedAt,
                    'lost_reason_id' => $lost ? $lostReason?->id : null,
                    'lost_at' => $lost ? $createdAt->copy()->addDays(4) : null,
                    'won_at' => $won ? $createdAt->copy()->addDays(9) : null,
                ]);

                /*
                 * Every seventh lead arrives twice, which is roughly what a
                 * retrying webhook looks like in production.
                 *
                 * Seven rather than five: the platform is picked with `$i % 5`,
                 * so a fifth lead would put every single duplicate on Meta and
                 * the per-source breakdown would show one source repeating and
                 * five that never do — a pattern in the fixtures, not in the
                 * world.
                 */
                if ($i % 7 === 0) {
                    $this->duplicateArrival($tenant, $filler, 3);
                }
            }

            // --- Follow-ups: exactly two per bucket, owned by the admin ------
            $this->followUps($tenant, $admin);
        });
    }

    private function seedBeta(TenantContext $context): void
    {
        $result = app(TenantProvisioningService::class)->provision([
            'company_name' => 'شركة المدار القابضة',
            'system_name' => 'المدار CRM',
            'subdomain' => self::BETA_SLUG,
            // Visibly different identity, so white-label assertions are unambiguous.
            'primary_color' => '#b91c1c',
            'secondary_color' => '#450a0a',
            'logo_url' => 'https://placehold.co/96x96/b91c1c/ffffff/png?text=B',
            'admin_name' => 'مدير المدار',
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
                'campaign_name' => 'حملة المدار السرية',
            ]);
        });
    }

    /**
     * A little automation history.
     *
     * A workspace that has been running has runs behind it, and a fixture set
     * with none makes every automation screen look like a feature nobody
     * switched on. The rule is enabled and its runs are spread across the last
     * fortnight, including skips and one failure — the states that matter are
     * the ones that are not success.
     */
    private function seedAutomationHistory(Tenant $tenant, User $admin): void
    {
        $rule = AutomationRule::create([
            'name' => 'اهتمام مرتفع: أولوية قصوى',
            'trigger' => 'lead.created',
            'is_enabled' => true,
            'priority' => 5,
            'conditions' => [['field' => 'temperature', 'operator' => 'equals', 'value' => 'hot']],
            'actions' => [['type' => 'adjust_score', 'by' => 10]],
            'created_by' => $admin->id,
        ]);

        $leads = Lead::query()->limit(6)->get();

        if ($leads->isEmpty()) {
            return;
        }

        $plan = [
            ['applied', 12], ['applied', 10], ['skipped', 9], ['applied', 7],
            ['skipped', 6], ['skipped', 5], ['applied', 3], ['failed', 2],
            ['applied', 1], ['skipped', 0],
        ];

        foreach ($plan as $index => [$outcome, $daysAgo]) {
            $run = AutomationRun::create([
                'lead_id' => $leads[$index % $leads->count()]->id,
                'automation_rule_id' => $rule->id,
                'trigger' => 'lead.created',
                'outcome' => $outcome,
                'reason' => $outcome === 'skipped'
                    ? 'اهتمام العميل ليس مرتفعًا'
                    : null,
                'applied_actions' => $outcome === 'applied'
                    ? [['ok' => true, 'type' => 'adjust_score', 'description' => 'التقييم 60 ← 70.']]
                    : [],
            ]);

            $run->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();
        }
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

        /*
         * `created_at` is applied after the insert, not through it: Eloquent
         * stamps its own timestamps on create and would overwrite a mass
         * assigned one. It has to land before the first-contact backfill
         * below, which is measured from it.
         */
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

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

        /*
         * A contacted lead gets a plausible contact time rather than the
         * model's backfill of "right now". The invariant would keep the
         * fixtures consistent either way, but a first contact stamped at the
         * same instant as the seed makes every response-time figure in the E2E
         * run read as zero seconds — fixtures should look like the data the
         * screens were designed for.
         */
        if ($createdAt !== null) {
            $lead->forceFill(['created_at' => $createdAt])->saveQuietly();
            $lead->refresh();
        }

        if ($lead->status->impliesContactWasMade()) {
            /*
             * Twelve minutes, bounded by how long the lead has existed.
             *
             * Several leads are seeded as arriving at this instant, and a flat
             * twelve minutes stamped their first contact in the future — a
             * response time nobody has yet had, in the figures the screens are
             * read against.
             */
            $elapsed = (int) $lead->created_at->diffInMinutes(now());

            $lead->forceFill([
                'first_contact_at' => $lead->created_at->copy()->addMinutes(min(12, max(0, $elapsed))),
            ])->saveQuietly();
        }

        LeadActivity::create([
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'type' => ActivityType::Created,
            'meta' => ['provider' => 'generic_webhook'],
        ]);

        /*
         * Every lead arrived from somewhere, so every seeded lead records the
         * arrival.
         *
         * Without this the fixtures held no `lead_source_events` at all, which
         * made the ingestion chart permanently empty in the E2E run — the same
         * shape of hole as the velocity chart's: a screen that cannot be
         * exercised because the data it reads is never created.
         */
        LeadSourceEvent::create([
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'provider' => $lead->provider ?? 'generic_webhook',
            'platform' => $lead->platform,
            'source' => $lead->source,
            'campaign_name' => $lead->campaign_name,
            'was_duplicate' => false,
            'received_at' => $lead->created_at,
        ]);

        return $lead;
    }

    /**
     * A second arrival of a lead already in the workspace.
     *
     * Duplicates are the normal failure of a lead form, not an exotic one: the
     * same payload posted twice by a retrying integration. The chart plots them
     * beside the accepted leads rather than netting them out, so the fixtures
     * have to contain some or that half of the chart is never drawn.
     */
    private function duplicateArrival(Tenant $tenant, Lead $lead, int $minutesLater): void
    {
        LeadSourceEvent::create([
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'provider' => $lead->provider ?? 'generic_webhook',
            'platform' => $lead->platform,
            'campaign_name' => $lead->campaign_name,
            'was_duplicate' => true,
            'received_at' => $lead->created_at->copy()->addMinutes($minutesLater),
        ]);
    }

    /** Two overdue, two due today, two upcoming — exact, so counts can be asserted. */
    private function followUps(Tenant $tenant, User $owner): void
    {
        $targets = Lead::whereIn('full_name', [
            'عميل تجريبي 01', 'عميل تجريبي 02', 'عميل تجريبي 03',
            'عميل تجريبي 04', 'عميل تجريبي 05', 'عميل تجريبي 06',
        ])->orderBy('full_name')->get();

        // Slots are relative to the moment of seeding so the buckets hold at any
        // hour: two already missed, two still ahead today, two on later days.
        $remainingToday = (int) now()->diffInMinutes(now()->endOfDay());

        $schedule = [
            now()->subDays(3),
            now()->subHours(2),
            now()->addMinutes(max(1, intdiv($remainingToday, 3))),
            now()->addMinutes(max(2, intdiv($remainingToday * 2, 3))),
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

    /**
     * A repository on the first project, so the Development tab has something
     * true to show.
     *
     * Every state the screen distinguishes appears exactly once: a build that
     * passed and one that failed, a deployment that was reported and one that
     * failed, a pull request ready to merge and one blocked by its build. A
     * fixture that only showed the happy path would let the one distinction
     * this feature exists for — reported is not verified — go untested.
     */
    private function seedDevelopment(Tenant $tenant, Project $project): void
    {
        $connection = Integration::create([
            'tenant_id' => $tenant->id,
            'provider' => IntegrationProvider::GitHub->value,
            'name' => 'GitHub',
            'status' => Integration::CONNECTED,
            // Not a real token: the fixtures never call GitHub, and a string
            // that looked like one would be the first thing somebody tried.
            'credentials' => ['access_token' => 'e2e-not-a-real-token'],
            'config' => ['account' => 'e2e-acme'],
            'is_active' => true,
        ]);

        $repository = ProjectRepository::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'integration_id' => $connection->id,
            'provider' => 'github',
            'repository_id' => 900_100,
            'repository_full_name' => 'acme/portal',
            'default_branch' => 'main',
            'develop_branch' => 'develop',
            'production_branch' => 'main',
            'webhook_key' => (string) Str::uuid(),
            'webhook_secret' => Str::random(40),
            'webhook_status' => 'ok',
            'last_webhook_at' => now()->subMinutes(6),
            'last_sync_at' => now()->subMinutes(3),
            'discovery' => ['unavailable' => []],
        ]);

        RepositoryPullRequest::create([
            'tenant_id' => $tenant->id, 'project_repository_id' => $repository->id,
            'number' => 93, 'title' => 'feat: the development tab', 'author_login' => 'sara',
            'head_branch' => 'feature/development-tab', 'base_branch' => 'develop',
            'state' => 'open', 'checks_state' => 'passed', 'review_state' => 'approved',
            'html_url' => 'https://github.com/acme/portal/pull/93',
            'opened_at' => now()->subHours(2), 'updated_at_github' => now()->subMinutes(20),
        ]);

        RepositoryPullRequest::create([
            'tenant_id' => $tenant->id, 'project_repository_id' => $repository->id,
            'number' => 92, 'title' => 'fix: the thing that broke', 'author_login' => 'omar',
            'head_branch' => 'fix/the-thing', 'base_branch' => 'develop',
            'state' => 'open', 'checks_state' => 'failed', 'review_state' => 'none',
            'html_url' => 'https://github.com/acme/portal/pull/92',
            'opened_at' => now()->subHours(5), 'updated_at_github' => now()->subHour(),
        ]);

        RepositoryWorkflowRun::create([
            'tenant_id' => $tenant->id, 'project_repository_id' => $repository->id,
            'run_id' => 77_001, 'name' => 'Backend tests', 'event' => 'push',
            'branch' => 'main', 'commit_sha' => str_repeat('a', 40),
            'status' => 'completed', 'conclusion' => 'success', 'environment' => 'production',
            'html_url' => 'https://github.com/acme/portal/actions/runs/77001',
            'completed_at' => now()->subMinutes(25),
        ]);

        RepositoryWorkflowRun::create([
            'tenant_id' => $tenant->id, 'project_repository_id' => $repository->id,
            'run_id' => 77_002, 'name' => 'Frontend tests', 'event' => 'push',
            'branch' => 'develop', 'commit_sha' => str_repeat('b', 40),
            'status' => 'completed', 'conclusion' => 'failure', 'environment' => 'staging',
            'html_url' => 'https://github.com/acme/portal/actions/runs/77002',
            'completed_at' => now()->subMinutes(12),
        ]);

        // Reported, not verified. The whole point.
        RepositoryDeployment::create([
            'tenant_id' => $tenant->id, 'project_repository_id' => $repository->id,
            'deployment_id' => 5_001, 'environment' => 'production', 'ref' => 'main',
            'commit_sha' => str_repeat('a', 40), 'state' => 'success',
            'verification_status' => 'unverifiable', 'updated_at_github' => now()->subMinutes(24),
        ]);

        RepositoryDeployment::create([
            'tenant_id' => $tenant->id, 'project_repository_id' => $repository->id,
            'deployment_id' => 5_002, 'environment' => 'staging', 'ref' => 'develop',
            'state' => 'failure', 'updated_at_github' => now()->subMinutes(10),
        ]);

        RepositoryRelease::create([
            'tenant_id' => $tenant->id, 'project_repository_id' => $repository->id,
            'release_id' => 3_001, 'tag_name' => 'v1.6.0', 'name' => 'إصدار الاختبار',
            'commit_sha' => 'main', 'author_login' => 'sara',
            'html_url' => 'https://github.com/acme/portal/releases/tag/v1.6.0',
            'published_at' => now()->subMinutes(30),
        ]);

        foreach ([
            ['pr_merged', '#91 feat: the sweep', 'sara', '91', str_repeat('c', 40), 40],
            ['ci_failed', 'Frontend tests', null, 'develop', str_repeat('b', 40), 12],
            ['deployed', 'production', null, 'main', str_repeat('a', 40), 24],
            ['released', 'إصدار الاختبار', 'sara', 'v1.6.0', '', 30],
            ['commit', 'feat: something that shipped', 'omar', 'main', str_repeat('d', 40), 55],
        ] as [$kind, $title, $actor, $ref, $sha, $minutes]) {
            RepositoryActivity::create([
                'tenant_id' => $tenant->id, 'project_repository_id' => $repository->id,
                'kind' => $kind, 'title' => $title, 'actor' => $actor,
                'ref' => $ref, 'commit_sha' => $sha, 'happened_at' => now()->subMinutes($minutes),
            ]);
        }
    }
}
