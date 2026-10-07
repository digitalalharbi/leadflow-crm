<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a campaign cost, so the leads it produced can be priced.
 *
 * The product already counts leads, qualified leads and sales per platform and
 * campaign; it has never known what any of it cost, so "which campaign is worth
 * scaling" has only ever been answerable by volume. Volume is the question that
 * flatters the cheapest traffic.
 *
 * Entered by hand or by CSV today, because that needs no ad-account access at
 * all. The same rows are what a Meta or Google insights sync would write when
 * credentials exist, so nothing downstream has to change when they do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_spends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            /*
             * Matched against the lead's own platform and campaign_name, which
             * is how every other report in this product groups. A campaign with
             * no name is '—' there and must be spellable here too, or its spend
             * can never be attributed.
             */
            $table->string('platform');
            $table->string('campaign_name');

            /*
             * A day, not a range. Ad platforms report daily, every range a
             * reader asks for is a sum of days, and storing ranges means
             * deciding what to do when two of them overlap.
             */
            $table->date('spend_date');

            // Minor units: 15.33 SAR is 1533, and no sum of money in this table
            // is ever a float.
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);

            // manual | import | api — so a later platform sync can overwrite
            // what it owns without touching what somebody typed.
            $table->string('source')->default('manual');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /*
             * One figure per campaign per day. A second upload of the same day
             * corrects the first rather than doubling it, which is the whole
             * reason this is a unique key and not an append-only log.
             */
            $table->unique(['tenant_id', 'platform', 'campaign_name', 'spend_date'], 'campaign_spends_unique_day');
            $table->index(['tenant_id', 'spend_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_spends');
    }
};
