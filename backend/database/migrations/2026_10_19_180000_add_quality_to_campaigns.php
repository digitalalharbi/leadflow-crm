<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How real a campaign's leads are, as one number the Leads Agent keeps current —
 * and the number before it, so a drop is something that can be noticed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->unsignedSmallInteger('quality_score')->nullable()->after('status');
            $table->unsignedSmallInteger('quality_previous')->nullable()->after('quality_score');
            $table->timestamp('quality_scored_at')->nullable()->after('quality_previous');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['quality_score', 'quality_previous', 'quality_scored_at']);
        });
    }
};
