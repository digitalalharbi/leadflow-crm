<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three thresholds that were constants: when a lead is worth a priority
 * call, what counts as a high-value lead, and how long untouched makes a lead
 * stalled. Null means "the default" — a workspace that never opens the screen
 * behaves exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('score_priority_threshold')->nullable();
            $table->unsignedInteger('high_value_from')->nullable();
            $table->unsignedSmallInteger('stalled_after_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', fn (Blueprint $t) => $t->dropColumn(['score_priority_threshold', 'high_value_from', 'stalled_after_days']));
    }
};
