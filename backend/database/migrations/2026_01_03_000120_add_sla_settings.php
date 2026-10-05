<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The escalation ladder, per tenant.
 *
 * `first_contact_target_minutes` already said when a lead is late. These say
 * what happens next, which is the part that stops a lead being ignored: remind
 * the rep, tell the manager, then take it back and give it to someone else.
 *
 * Zero disables a rung without disabling the ones after it, so a workspace can
 * escalate to a manager without ever reassigning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('sla_remind_after_minutes')->default(10)
                ->after('first_contact_target_minutes');
            $table->unsignedSmallInteger('sla_escalate_after_minutes')->default(30)
                ->after('sla_remind_after_minutes');
            $table->unsignedSmallInteger('sla_reassign_after_minutes')->default(60)
                ->after('sla_escalate_after_minutes');
        });

        Schema::table('leads', function (Blueprint $table) {
            // Which rungs have already fired, so a lead is never nagged twice
            // for the same breach.
            $table->timestamp('sla_reminded_at')->nullable()->after('first_response_at');
            $table->timestamp('sla_escalated_at')->nullable()->after('sla_reminded_at');
            $table->timestamp('sla_reassigned_at')->nullable()->after('sla_escalated_at');

            // The scheduler's read path: open, assigned, never contacted.
            $table->index(['tenant_id', 'status', 'first_contact_at'], 'leads_sla_sweep_index');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn([
                'sla_remind_after_minutes',
                'sla_escalate_after_minutes',
                'sla_reassign_after_minutes',
            ]);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_sla_sweep_index');
            $table->dropColumn(['sla_reminded_at', 'sla_escalated_at', 'sla_reassigned_at']);
        });
    }
};
