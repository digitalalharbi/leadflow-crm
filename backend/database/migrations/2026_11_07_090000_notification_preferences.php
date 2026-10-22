<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How each person wants to hear about things: per kind of alert (instant, in the daily or weekly
 * digest, or not at all) and which digests they get. Null means "the defaults for their role" —
 * which is what everybody is until they change something, so a new colleague is never silent and
 * never flooded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_prefs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('notification_prefs'));
    }
};
