<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automation a tenant owns.
 *
 * Conditions and actions are JSON on purpose, and this is the one place in the
 * schema where that is the right call: their shape is defined by the rule
 * builder, varies per rule, and is never queried by its interior — a rule is
 * always loaded whole, by trigger. Modelling every operator as a column would
 * buy nothing and cost a migration per new condition type.
 *
 * Everything the engine *does* query — tenant, trigger, enabled, order — is a
 * real column with a real index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('trigger', 60);
            $table->boolean('is_enabled')->default(true);

            // Lower runs first. Ties break on id, so order is always total.
            $table->unsignedSmallInteger('priority')->default(100);

            // When true, no later rule runs for this event.
            $table->boolean('stops_processing')->default(false);

            $table->jsonb('conditions')->default('[]');
            $table->jsonb('actions')->default('[]');

            // Operational counters, so a rule that never matches is visible
            // without reading logs.
            $table->unsignedInteger('times_matched')->default(0);
            $table->timestamp('last_matched_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // The engine's only read path: every enabled rule for one trigger,
            // in order.
            $table->index(['tenant_id', 'trigger', 'is_enabled', 'priority'], 'automation_rules_dispatch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rules');
    }
};
