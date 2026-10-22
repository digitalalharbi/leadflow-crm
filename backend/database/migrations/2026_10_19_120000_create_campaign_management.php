<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign → ad set → ad → creative, and what each of them did each day.
 *
 * Until now a "campaign" in this product was a string on a lead. That was
 * enough to count leads per campaign and not enough to ask the questions an ad
 * buyer actually has — what it cost, whether it is still running, which ad of
 * the four is carrying it. These tables hold the ad platform's own structure,
 * and they are filled from two honest sources:
 *
 *   sync          read from the platform's API, when the workspace has connected
 *                 an ad account and the adapter for that platform exists;
 *   attribution   created from the leads themselves, so a campaign that brought
 *                 leads is on the screen even when nobody has connected an
 *                 account yet — with no spend, because nobody has said what it
 *                 cost.
 *
 * Nothing here is ever deleted. A campaign that stops existing on the platform
 * is archived: its leads, its history and the sales it produced are still true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('integration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 24);
            $table->string('external_id');
            $table->string('name')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'platform', 'external_id']);
        });

        Schema::create('creatives', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 24);
            $table->string('external_id')->nullable();
            $table->string('name')->nullable();
            // image | video | carousel | text
            $table->string('format', 16)->nullable();
            $table->string('headline', 500)->nullable();
            $table->text('body')->nullable();
            $table->string('call_to_action', 64)->nullable();
            $table->text('media_url')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->jsonb('raw')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'platform', 'external_id']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ad_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 24);
            $table->string('external_id')->nullable();
            $table->string('name');
            // sync | lead_attribution | manual — where this row came from.
            $table->string('origin', 24)->default('lead_attribution');
            $table->string('objective')->nullable();
            // active | paused | ended | unknown. Unknown is a real answer: a
            // campaign known only from its leads has no status anybody read.
            $table->string('status', 16)->default('unknown');
            $table->unsignedBigInteger('daily_budget_minor')->nullable();
            $table->unsignedBigInteger('lifetime_budget_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->jsonb('raw')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'platform', 'external_id']);
            $table->index(['tenant_id', 'platform', 'name']);
            $table->index(['tenant_id', 'project_id']);
        });

        Schema::create('ad_sets', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->string('status', 16)->default('unknown');
            $table->unsignedBigInteger('daily_budget_minor')->nullable();
            $table->jsonb('targeting')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'campaign_id']);
        });

        Schema::create('ads', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ad_set_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('creative_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->string('status', 16)->default('unknown');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'campaign_id']);
        });

        /*
         * One row per entity per day, at the finest level the platform reports.
         *
         * Money is minor units, always; a sum of floats is how a budget total
         * ends up eleven halalas from the invoice. `level` says what the row
         * describes, and an adapter must not report the same campaign at two
         * levels — the roll-up sums every row for a campaign, so mixing levels
         * would count a day's spend twice.
         */
        Schema::create('metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ad_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 24);
            $table->string('level', 12); // campaign | ad_set | ad
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ad_set_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('ad_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('spend_minor')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            // What the platform says it delivered. CRM outcomes are read from
            // the leads themselves; these are the platform's own claims.
            $table->unsignedInteger('leads')->default(0);
            $table->unsignedInteger('purchases')->default(0);
            $table->unsignedBigInteger('revenue_minor')->default(0);
            $table->string('currency', 3)->nullable();
            $table->jsonb('raw')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'campaign_id', 'date']);
            $table->index(['tenant_id', 'project_id', 'date']);
        });

        // One figure per entity per day: a second sync of the same day corrects
        // the first rather than adding to it. An expression index because the
        // ad-set and ad ids are null at the levels above them, and null never
        // equals null in a plain unique constraint.
        DB::statement('create unique index metric_snapshots_one_per_day on metric_snapshots
            (tenant_id, level, campaign_id, coalesce(ad_set_id, 0), coalesce(ad_id, 0), date)');

        Schema::table('leads', function (Blueprint $table) {
            // Which campaign, ad set and ad this lead belongs to, as rows rather
            // than as the strings the form carried. The strings stay: they are
            // what the platform sent, and they are how a campaign is found again.
            $table->foreignId('campaign_ref_id')->nullable()->after('campaign_name')->constrained('campaigns')->nullOnDelete();
            $table->foreignId('ad_set_ref_id')->nullable()->after('campaign_ref_id')->constrained('ad_sets')->nullOnDelete();
            $table->foreignId('ad_ref_id')->nullable()->after('ad_set_ref_id')->constrained('ads')->nullOnDelete();
            $table->index(['tenant_id', 'campaign_ref_id']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ad_ref_id');
            $table->dropConstrainedForeignId('ad_set_ref_id');
            $table->dropConstrainedForeignId('campaign_ref_id');
        });

        Schema::dropIfExists('metric_snapshots');
        Schema::dropIfExists('ads');
        Schema::dropIfExists('ad_sets');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('creatives');
        Schema::dropIfExists('ad_accounts');
    }
};
