<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications that arrive once.
 *
 *  - `follow_ups.reminded_at` / `overdue_reminded_at`: the hourly reminder job
 *    used to re-notify every due and overdue follow-up on every run.
 *  - `alert_marks`: one row per thing already announced ("hot:42",
 *    "automation_failed:7:2026-10-26"), so an alert can be sent once and
 *    never again for the same thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('overdue_reminded_at')->nullable();
        });

        Schema::create('alert_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 190);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_marks');
        Schema::table('follow_ups', fn (Blueprint $t) => $t->dropColumn(['reminded_at', 'overdue_reminded_at']));
    }
};
