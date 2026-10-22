<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The onboarding checklist no longer stores what it has decided.
 *
 * It used to be a map of booleans that an endpoint set on request, which meant
 * the card could report "team added" about a workspace with no sales reps in
 * it. In practice nothing ever called that endpoint, so it reported the same
 * thing forever regardless of what anybody did — a checklist satisfied by
 * pressing Next, which is the kind that lies on the day it matters.
 *
 * Every item is now answered by looking at the data, so there is nothing left
 * to store. Dropped rather than left behind: a column nobody writes is a column
 * somebody reads by accident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn('onboarding');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->json('onboarding')->nullable();
        });
    }
};
