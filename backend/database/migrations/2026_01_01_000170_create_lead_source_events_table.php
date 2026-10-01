<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_source_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('platform')->nullable();
            $table->string('source')->nullable();
            $table->string('campaign_name')->nullable();
            $table->string('campaign_id')->nullable();
            $table->string('adset_name')->nullable();
            $table->string('ad_name')->nullable();
            $table->string('form_name')->nullable();
            $table->string('provider_lead_id')->nullable();
            $table->boolean('was_duplicate')->default(false);
            $table->jsonb('payload')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['tenant_id', 'lead_id']);
            $table->index(['tenant_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_source_events');
    }
};
