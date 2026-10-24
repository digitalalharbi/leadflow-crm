<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Mohtam sold to many independent companies. Additive only — no tenant row loses anything.
 *
 *  - `plans`: the catalogue the platform owner manages from the console. Prices start EMPTY: a price is a business
 *    decision, never a number in code, and a plan with no price makes revenue read "not configured", not zero.
 *  - `tenants` gains the subscription it is on: plan, seats bought on top of the plan, add-on features, per-company
 *    overrides, the billing provider's references, and when it was suspended or cancelled.
 *  - `invoices`: references to what was billed — issued by hand from the console until a payment provider is wired.
 *  - `platform_audit_logs`: what the platform owner did to which company. Kept apart from a company's own trail, which
 *    its admins read.
 *
 * Every existing workspace is moved onto the plan that includes everything it could already do, so nothing it uses
 * disappears the day this ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('key', 40)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price_monthly', 12, 2)->nullable();
            $table->decimal('price_yearly', 12, 2)->nullable();
            $table->decimal('extra_seat_price', 12, 2)->nullable();
            $table->string('currency', 3)->default('SAR');
            $table->unsignedInteger('included_seats')->default(3);
            $table->unsignedInteger('max_seats')->nullable();
            $table->json('limits');
            $table->json('features');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('plan')->constrained('plans')->nullOnDelete();
            $table->string('billing_cycle', 10)->default('monthly')->after('plan_id');
            $table->unsignedInteger('extra_seats')->default(0)->after('billing_cycle');
            $table->unsignedInteger('seat_limit_override')->nullable()->after('extra_seats');
            $table->json('limit_overrides')->nullable()->after('seat_limit_override');
            $table->json('addons')->nullable()->after('limit_overrides');
            $table->string('billing_provider', 30)->default('manual')->after('addons');
            $table->string('billing_customer_ref')->nullable()->after('billing_provider');
            $table->string('billing_subscription_ref')->nullable()->after('billing_customer_ref');
            $table->timestamp('suspended_at')->nullable()->after('subscription_ends_at');
            $table->timestamp('cancelled_at')->nullable()->after('suspended_at');
            $table->string('status_reason', 500)->nullable()->after('cancelled_at');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('number', 40)->unique();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('status', 12)->default('issued'); // draft | issued | paid | void
            $table->string('provider', 30)->default('manual');
            $table->string('provider_ref')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        $now = now();
        $all = ['tracking_hub', 'advanced_forms', 'automations', 'attribution', 'exports', 'api', 'advanced_reports', 'whatsapp', 'custom_branding', 'multiple_projects'];

        // A starting catalogue the owner edits: names, seats, limits and features. No prices.
        $catalogue = [
            ['starter', 'Starter', 3, 5, ['projects' => 1, 'leads_per_month' => 500, 'storage_mb' => 1024],
                ['exports']],
            ['growth', 'Growth', 5, 15, ['projects' => 5, 'leads_per_month' => 3000, 'storage_mb' => 5120],
                ['tracking_hub', 'advanced_forms', 'automations', 'attribution', 'exports', 'multiple_projects', 'whatsapp']],
            ['business', 'Business', 10, 40, ['projects' => 20, 'leads_per_month' => 15000, 'storage_mb' => 20480],
                ['tracking_hub', 'advanced_forms', 'automations', 'attribution', 'exports', 'api', 'advanced_reports', 'whatsapp', 'multiple_projects']],
            ['enterprise', 'Enterprise', 25, null, ['projects' => null, 'leads_per_month' => null, 'storage_mb' => null],
                $all],
        ];

        foreach ($catalogue as $i => [$key, $name, $seats, $max, $limits, $features]) {
            DB::table('plans')->insert([
                'uuid' => (string) Str::uuid(), 'key' => $key, 'name' => $name,
                'included_seats' => $seats, 'max_seats' => $max,
                'limits' => json_encode($limits), 'features' => json_encode($features),
                'currency' => 'SAR', 'is_active' => true, 'sort_order' => $i,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Existing workspaces keep every feature they had. Nobody already on a team is ever switched off by a seat limit:
        // the limit only refuses the next seat.
        $enterprise = DB::table('plans')->where('key', 'enterprise')->value('id');
        DB::table('tenants')->whereNull('plan_id')->update(['plan_id' => $enterprise]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_logs');
        Schema::dropIfExists('invoices');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
            $table->dropColumn([
                'billing_cycle', 'extra_seats', 'seat_limit_override', 'limit_overrides', 'addons',
                'billing_provider', 'billing_customer_ref', 'billing_subscription_ref',
                'suspended_at', 'cancelled_at', 'status_reason',
            ]);
        });

        Schema::dropIfExists('plans');
    }
};
