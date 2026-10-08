<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a rep is back, rather than whether they are switched on.
 *
 * Assignment's only gate was `is_active`, which is an account state: the thing
 * you set when somebody leaves the company, not when they are on leave until
 * Sunday. So leads kept going to whoever was away, and the balancer's own
 * docblock already said why that matters — "assigning leads to someone who is
 * away is how a lead sits untouched for a day inside a system whose whole
 * promise is that it will not."
 *
 * A date rather than a flag, because a flag is a thing somebody has to remember
 * to clear, and the Sunday they forget is a week of leads going nowhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('away_until')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('away_until');
        });
    }
};
