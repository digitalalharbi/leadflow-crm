<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which kind of deal a rep takes — buy, rent — so the lead's type can be one of the reasons it
 * lands on them. Empty means every kind, like cities and languages: it narrows nothing until
 * somebody says what their team does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('handles')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('handles'));
    }
};
