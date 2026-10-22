<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which repository a project is built in.
 *
 * One project, one repository, and the repository identified by its numeric id
 * rather than its name: `acme/portal` becomes `acme/portal-v2` the day somebody
 * renames it, and every webhook after that would arrive about a repository this
 * table had never heard of. The full name is kept too, because it is what a
 * person reads and what a URL is built from.
 *
 * The branch columns are a *mapping*, not a deployment instruction. مهتم reads
 * them to decide which CI run to call "staging" and which to call "production";
 * nothing here changes what the repository itself does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_repositories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // The connection the credentials live on. Restricted rather than
            // cascading: disconnecting GitHub must not silently delete the
            // development history of every project that used it.
            $table->foreignId('integration_id')->constrained()->restrictOnDelete();

            $table->string('provider', 32)->default('github');
            $table->unsignedBigInteger('repository_id');
            $table->string('repository_full_name');
            $table->string('default_branch', 255)->default('main');
            $table->string('develop_branch', 255)->nullable();
            $table->string('production_branch', 255)->nullable();

            /*
             * Where the last reconciliation got to. A sweep asks "what changed
             * since this" rather than re-reading the repository, which is the
             * difference between a sustainable ten-minute job and one that
             * spends an hour's API budget before lunch.
             */
            $table->timestampTz('sync_cursor')->nullable();
            $table->timestampTz('last_sync_at')->nullable();
            $table->timestampTz('last_webhook_at')->nullable();

            // What the repository could and could not tell us, kept so the
            // readiness panel can say *why* something is missing.
            $table->jsonb('discovery')->nullable();
            $table->string('last_error')->nullable();

            $table->timestamps();

            // A project has one repository; the same repository may legitimately
            // back two projects in the same workspace (a monorepo), so the
            // uniqueness is on the project.
            $table->unique('project_id');
            $table->index(['tenant_id', 'repository_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_repositories');
    }
};
