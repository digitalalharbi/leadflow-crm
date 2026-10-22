<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why the system did what it did.
 *
 * One row per rule considered for one event — including the rules that did not
 * match, because "why was nothing sent?" is asked at least as often as "why was
 * this sent?", and a log that only records successes cannot answer it.
 *
 * `outcome` separates the three endings that look identical from outside: the
 * conditions did not match, they matched and the actions ran, or they matched
 * and an action failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_rule_id')->nullable()
                ->constrained('automation_rules')->nullOnDelete();

            $table->string('trigger', 60);
            $table->string('outcome', 20); // skipped | applied | failed

            // The specific condition that rejected it, or the action that broke.
            $table->text('reason')->nullable();

            // What the actions actually did, for the trace on the lead screen.
            $table->jsonb('applied_actions')->default('[]');

            // Ties every run from one event together, so a trace reads as one
            // decision rather than a scatter of rows.
            $table->uuid('correlation_id')->nullable();

            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['lead_id', 'created_at'], 'automation_runs_lead_trace_index');
            $table->index(['tenant_id', 'outcome', 'created_at'], 'automation_runs_health_index');
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
    }
};
