<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which languages a rep can sell in.
 *
 * `locale` already exists and is a different thing: it is the language this
 * person wants مهتم's own screens in. A rep who reads the interface in Arabic
 * may well handle English calls, and one whose interface is English is not
 * thereby unable to speak Arabic — so using `locale` to route customers would
 * be routing on a UI preference.
 *
 * Empty means no claim, and no claim means every language, which is where every
 * workspace starts: a team that never fills this in keeps the behaviour it has
 * today. The claim only ever narrows once somebody makes one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('speaks')->nullable()->after('covers_cities');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('speaks');
        });
    }
};
