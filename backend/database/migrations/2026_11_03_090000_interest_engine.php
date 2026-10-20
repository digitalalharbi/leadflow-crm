<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How interested a lead is — a number and a level of its own, apart from the lead's score.
 *
 * The score says how good a fit the lead is on paper (budget, timeline, how complete the answers
 * are). Interest says what they are DOING: replying, opening the brochure, coming back to the
 * project page, asking the price, asking for a visit, asking to reserve — and what is going cold.
 * A perfect-fit lead who never answers is a high score and low interest; a modest budget who is
 * asking to reserve is the reverse, and it is the second one a rep should call first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedSmallInteger('interest_score')->default(0)->after('score');
            // cold · warm · interested · hot · visit_ready · reservation_ready
            $table->string('interest_level', 20)->default('cold')->after('interest_score');
            // A person's word beats the arithmetic: "interested" sets a floor, "not_interested" forces cold.
            $table->string('interest_override', 20)->nullable()->after('interest_level');
            $table->json('interest_signals')->nullable()->after('interest_override');
            $table->timestamp('interest_at')->nullable()->after('interest_signals');
            $table->timestamp('interest_level_changed_at')->nullable()->after('interest_at');

            $table->index(['tenant_id', 'interest_level']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'interest_level']);
            $table->dropColumn(['interest_score', 'interest_level', 'interest_override', 'interest_signals', 'interest_at', 'interest_level_changed_at']);
        });
    }
};
