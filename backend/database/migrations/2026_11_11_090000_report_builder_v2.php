<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report builder v2: a report is an ordered list of sections, each with its own switch, audience
 * and period; it can compare itself with the period before and says how often an open page
 * refreshes. Every opening of a link is kept (who is only ever a hash) so a link has view analytics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_shares', function (Blueprint $table) {
            $table->json('layout')->nullable();
            $table->boolean('compare')->default(false);
            $table->unsignedSmallInteger('refresh_seconds')->default(60);
        });

        Schema::create('report_share_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_share_id')->constrained()->cascadeOnDelete();
            $table->string('viewer_hash', 64);          // sha256 of address + browser + the link: tells visitors apart, names nobody
            $table->string('device', 8);                // desktop · mobile · tablet · other
            $table->timestamp('viewed_at')->useCurrent();

            $table->index(['report_share_id', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_share_views');
        Schema::table('report_shares', fn (Blueprint $t) => $t->dropColumn(['layout', 'compare', 'refresh_seconds']));
    }
};
