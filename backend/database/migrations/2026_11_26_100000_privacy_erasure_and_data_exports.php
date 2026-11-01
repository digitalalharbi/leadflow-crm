<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things a company owes the people in its pipeline and itself: a person can be erased — their name, number,
 * words and answers gone, the counts kept — and the company can take everything it holds away with it. Exports run
 * in the background and leave a file that a signed link opens for a short while, then is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('erased_at')->nullable();
        });

        Schema::create('data_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 32);
            $table->string('format', 8);
            $table->jsonb('filters')->nullable();
            $table->string('status', 16)->default('queued');
            $table->string('path')->nullable();
            $table->string('filename')->nullable();
            $table->unsignedInteger('rows')->default(0);
            $table->unsignedBigInteger('bytes')->default(0);
            $table->string('error', 255)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('erased_at');
        });
    }
};
