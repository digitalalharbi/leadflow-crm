<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a rep sells, and how much of the load they carry.
 *
 * Both default to "no opinion": an empty coverage list means every city, and a
 * weight of one means an equal share. A workspace that never touches either
 * keeps exactly the behaviour it has today, which is the only honest default
 * for something that decides who talks to a customer.
 *
 * Cities are free text on a lead — they arrive from ad platform forms and are
 * whatever the customer typed — so coverage is a list of strings matched
 * loosely rather than a foreign key to a table nobody maintains.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('covers_cities')->nullable()->after('away_until');

            /*
             * How many leads this rep carries for every one an ordinary rep
             * takes. A senior closer on 3 gets three times the share; somebody
             * training on 1 against a team of 2s gets half.
             *
             * Capped at ten, because past that it is not a weighting, it is a
             * rule that one person takes everything — and that is better said
             * by naming them.
             */
            $table->unsignedSmallInteger('assignment_weight')->default(1)->after('covers_cities');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['covers_cities', 'assignment_weight']);
        });
    }
};
