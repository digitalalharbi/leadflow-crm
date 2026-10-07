<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a rule needs all of its conditions or any one of them.
 *
 * Conditions have always been a flat list combined with AND, on the reasoning
 * that nested boolean trees are a second rule rather than a deeper structure
 * anybody can read back. That reasoning holds, and it is kept: this is one
 * choice for the whole list, not a tree.
 *
 * What it unlocks is the rule that could not be written at all. "Call back" and
 * "no answer" are different outcomes, and a workspace that wants one follow-up
 * sequence for both had to write the rule twice and keep the two in step by
 * hand. So did "Riyadh or Jeddah" wherever the field is not a list.
 *
 * `all` is the default, so every existing rule means exactly what it meant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_rules', function (Blueprint $table) {
            $table->string('condition_match', 3)->default('all')->after('conditions');
        });
    }

    public function down(): void
    {
        Schema::table('automation_rules', function (Blueprint $table) {
            $table->dropColumn('condition_match');
        });
    }
};
