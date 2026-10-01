<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            /*
             * How long a new lead may sit untouched before the dashboard calls
             * it neglected. Thirty minutes is the default because an ad lead
             * answered within half an hour is a conversation and one answered
             * the next day is a cold call — but the right number depends on how
             * the team works, so it is theirs to set.
             */
            $table->unsignedSmallInteger('first_contact_target_minutes')->default(30)->after('score_hot_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn('first_contact_target_minutes');
        });
    }
};
