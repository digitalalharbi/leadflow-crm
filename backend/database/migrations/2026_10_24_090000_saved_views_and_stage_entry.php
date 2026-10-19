<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two small facts the lists and the SLA ring need:
 *
 *  - when a lead entered its current stage (so "time in stage" is a subtraction
 *    and not a search through the activity log);
 *  - the views a person saved from the filters they had set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('stage_entered_at')->nullable();
        });

        // Existing leads: the last time their stage changed, else when they arrived.
        DB::statement("update leads set stage_entered_at = coalesce(
            (select max(created_at) from lead_activities a where a.lead_id = leads.id and a.type = 'status_changed'),
            leads.created_at)");

        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('filters');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
        Schema::table('leads', fn (Blueprint $t) => $t->dropColumn('stage_entered_at'));
    }
};
