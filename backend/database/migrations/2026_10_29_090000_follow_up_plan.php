<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The plan a lead's follow-up was laid out as: six moments, what each does and why. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->json('follow_up_plan')->nullable();
            $table->timestamp('follow_up_planned_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', fn (Blueprint $t) => $t->dropColumn(['follow_up_plan', 'follow_up_planned_at']));
    }
};
