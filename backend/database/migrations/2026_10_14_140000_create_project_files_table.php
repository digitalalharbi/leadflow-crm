<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The files a project sells with, and whether anybody opened them.
 *
 * A rep sending a brochure today finds it in WhatsApp, or on a shared drive, or
 * in an email from March — and sends the Arabic one to an English speaker about
 * as often as not. Worse, once sent it vanishes: nobody knows whether the
 * customer opened it, which is the single most useful thing a sales team could
 * know before deciding who to call.
 *
 * So files belong to the project, carry what they are and who they are for, and
 * are shared through a link that reports back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            /*
             * What this file is for, which is what makes automatic selection
             * possible: an investment deck is the right answer for somebody who
             * said they are buying to invest, and the wrong one for a family
             * looking at a three-bedroom.
             */
            $table->string('kind', 32)->default('brochure');
            /*
             * Who it is for. Null means any language — a price list of numbers
             * does not need two versions, and forcing one would mean a project
             * with no Arabic copy has no price list at all.
             */
            $table->string('language', 8)->nullable();

            $table->string('path');
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'project_id', 'kind']);
        });

        /*
         * One link per file per lead.
         *
         * Per lead, not per file, because the question is never "how popular is
         * this brochure" — it is "did *this* customer open it", which is what
         * changes who gets called next. The token is what carries that, and it
         * is why the link cannot simply be a path to the file.
         */
        Schema::create('file_shares', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();

            // whatsapp | email | manual — how it reached them, for the timeline.
            $table->string('channel', 16)->default('manual');
            $table->string('token', 64)->unique();

            $table->unsignedInteger('opens')->default(0);
            $table->timestampTz('first_opened_at')->nullable();
            $table->timestampTz('last_opened_at')->nullable();
            $table->timestamps();

            $table->unique(['project_file_id', 'lead_id']);
            $table->index(['tenant_id', 'first_opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_shares');
        Schema::dropIfExists('project_files');
    }
};
