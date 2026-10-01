<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('full_name');

            $table->string('phone')->nullable();
            $table->string('phone_normalized', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('city')->nullable();

            // marketing attribution
            $table->string('platform')->nullable();     // meta | tiktok | google | linkedin | snapchat | website | manual ...
            $table->string('source')->nullable();       // free-form sub-source
            $table->string('campaign_name')->nullable();
            $table->string('campaign_id')->nullable();
            $table->string('adset_name')->nullable();
            $table->string('adset_id')->nullable();
            $table->string('ad_name')->nullable();
            $table->string('ad_id')->nullable();
            $table->string('form_name')->nullable();
            $table->string('form_id')->nullable();

            $table->string('status')->default('new');       // new|contacted|qualified|appointment|won|lost
            $table->string('temperature')->default('cold'); // hot|warm|cold
            $table->unsignedSmallInteger('score')->default(0);

            $table->decimal('budget_min', 14, 2)->nullable();
            $table->decimal('budget_max', 14, 2)->nullable();
            $table->string('purchase_purpose')->nullable();     // residence | investment
            $table->string('payment_method')->nullable();       // cash | financing
            $table->string('financing_status')->nullable();
            $table->string('preferred_property_type')->nullable();
            $table->string('preferred_location')->nullable();
            $table->string('purchase_timeline')->nullable();

            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamp('last_contact_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->foreignId('lost_reason_id')->nullable()->constrained('lost_reasons')->nullOnDelete();
            $table->text('lost_note')->nullable();

            $table->unsignedInteger('re_engaged_count')->default(0);

            // provider / ingestion traceability
            $table->string('provider')->nullable();
            $table->string('provider_lead_id')->nullable();
            $table->jsonb('provider_payload')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Duplicate detection key: one active lead per phone per tenant.
            $table->unique(['tenant_id', 'phone_normalized'], 'leads_tenant_phone_unique');

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'temperature']);
            $table->index(['tenant_id', 'assigned_to']);
            $table->index(['tenant_id', 'project_id']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'next_follow_up_at']);
            $table->index(['tenant_id', 'platform']);
            $table->index(['provider', 'provider_lead_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
