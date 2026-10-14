<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Google Sheet that leads arrive in.
 *
 * Plenty of teams run their campaigns through a sheet somebody fills in by
 * hand, and today that ends with a CSV export once a week — by which time the
 * leads at the top of it are cold. This makes the sheet an ordinary source:
 * read on a schedule, through the same pipeline as a webhook, with the same
 * deduplication and the same assignment.
 *
 * The sheet is **inbound only**. Nothing is ever written back to it and nothing
 * in مهتم is read from it twice: PostgreSQL is the record, and the sheet is
 * where some of it arrived from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheet_sources', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // The Google connection whose token reaches this sheet.
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();

            $table->string('spreadsheet_id');
            $table->string('spreadsheet_name')->nullable();
            // The tab. A spreadsheet with "Meta" and "TikTok" tabs is two
            // sources, not one, and mapping them separately is the whole point.
            $table->string('sheet_name');
            $table->unsignedSmallInteger('header_row')->default(1);

            /*
             * Which column means what: `{"full_name": "الاسم", "phone": "الجوال"}`.
             * Keyed by our field and valued by their header, because their
             * headers are the thing that changes and ours are not.
             */
            $table->jsonb('mapping');

            // Stamped onto every lead from this sheet — the project it belongs
            // to, the platform to credit. A sheet is usually one campaign.
            $table->jsonb('defaults')->nullable();

            /*
             * The last row this source has finished with, successfully or
             * otherwise. "Otherwise" matters: a row that can never be valid
             * must still move the cursor, or every sync spends itself failing
             * on the same row and never reaches the ones after it.
             */
            $table->unsignedInteger('cursor_row')->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_synced_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'spreadsheet_id', 'sheet_name']);
            $table->index(['tenant_id', 'is_active']);
        });

        /*
         * One row per sync, so "why did nothing arrive on Tuesday" has an
         * answer. Counts rather than rows: the rows are leads now, and keeping
         * a second copy of somebody's customer list in a log is not a feature.
         */
        Schema::create('sheet_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sheet_source_id')->constrained()->cascadeOnDelete();

            // scheduled | manual
            $table->string('trigger', 16)->default('scheduled');
            // running | completed | failed
            $table->string('status', 16)->default('running');

            $table->unsignedInteger('rows_seen')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedInteger('failed')->default(0);

            $table->unsignedInteger('cursor_before')->default(0);
            $table->unsignedInteger('cursor_after')->default(0);

            /*
             * Why individual rows were refused, capped. Enough to fix the sheet
             * — "row 41: phone number unreadable" — and not enough to become a
             * copy of the data that was refused.
             */
            $table->jsonb('failures')->nullable();
            $table->string('error')->nullable();

            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'started_at']);
            $table->index(['sheet_source_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheet_sync_runs');
        Schema::dropIfExists('sheet_sources');
    }
};
