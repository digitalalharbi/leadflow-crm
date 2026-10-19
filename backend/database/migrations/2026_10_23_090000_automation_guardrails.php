<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The limits automation keeps to when it writes to a person.
 *
 * Off or generous by default: a workspace opts in to a quiet window, and the
 * project pack switches the sensible ones on. A project may override the
 * workspace's quiet hours, SLA and cadence in `automation_settings`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->boolean('quiet_hours_enabled')->default(false);
            $table->string('quiet_hours_start', 5)->default('21:00');
            $table->string('quiet_hours_end', 5)->default('08:00');
            $table->unsignedSmallInteger('max_contacts_per_day')->default(6);
            $table->unsignedSmallInteger('contact_cooldown_minutes')->default(0);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->json('automation_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', fn (Blueprint $t) => $t->dropColumn([
            'quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end', 'max_contacts_per_day', 'contact_cooldown_minutes',
        ]));
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn('automation_settings'));
    }
};
