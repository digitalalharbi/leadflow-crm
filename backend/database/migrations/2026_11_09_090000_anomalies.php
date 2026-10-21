<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Things that moved outside their normal range — found by a nightly pass, kept so they can be
 * shown, dismissed and counted, and alerted once. `daily_figures` is the small memory the pass
 * needs for figures that are a state rather than a count (how big the backlog is *today*).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anomalies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);                       // lead_spike · lead_drop · cpl_spike · qualified_rate_drop · no_response_spike · tracking_drop · spend_no_qualified · backlog_growth
            $table->string('subject', 160)->default('');      // a campaign or a platform; empty for the workspace as a whole
            $table->string('severity', 10)->default('warning'); // warning · critical
            $table->date('detected_on');
            $table->decimal('observed', 14, 2)->nullable();
            $table->decimal('expected', 14, 2)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('alerted_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'kind', 'subject', 'detected_on']);
            $table->index(['tenant_id', 'dismissed_at', 'detected_on']);
        });

        Schema::create('daily_figures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('on_date');
            $table->string('key', 40);
            $table->decimal('value', 14, 2);
            $table->unique(['tenant_id', 'on_date', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_figures');
        Schema::dropIfExists('anomalies');
    }
};
