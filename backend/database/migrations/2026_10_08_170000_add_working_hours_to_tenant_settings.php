<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the business is open.
 *
 * The response-time clock counted wall-clock minutes, so a lead arriving at two
 * in the morning was reminded at ten past, escalated to the management at half
 * past, and taken off its rep by three — for a call nobody could have made. The
 * rep opened their laptop to a breach they could not have avoided, which is the
 * fastest way to teach a team that the SLA is noise.
 *
 * Null everywhere means always open, which is exactly the behaviour today, so
 * nothing changes for a workspace until somebody says when they work. Days are
 * ISO weekday numbers because `Carbon::dayOfWeekIso` is what reads them, and
 * Sunday-to-Thursday is a normal week here — the default has to be a choice the
 * workspace makes, not one we guess at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->jsonb('work_days')->nullable()->after('timezone');
            $table->time('work_starts_at')->nullable()->after('work_days');
            $table->time('work_ends_at')->nullable()->after('work_starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn(['work_days', 'work_starts_at', 'work_ends_at']);
        });
    }
};
