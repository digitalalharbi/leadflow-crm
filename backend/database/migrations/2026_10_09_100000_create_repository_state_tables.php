<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened in a project's repository.
 *
 * Five tables, because they answer five different questions and a manager asks
 * them separately: what is waiting to be merged, is the build green, did it
 * reach the server, what version is live, and what changed. One "events" table
 * would answer none of them without a query nobody wants to write twice.
 *
 * Everything here is a *projection* of what GitHub told us, keyed on GitHub's
 * own ids so that a redelivery, a reconciliation sweep and a webhook all write
 * the same row rather than three.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Every delivery GitHub makes, accepted or not.
         *
         * The point is idempotency: GitHub retries a delivery it thinks failed,
         * and a retried `pull_request.closed` must not merge the same PR twice
         * in the timeline. The delivery id is unique, so the second arrival is
         * recognised rather than replayed.
         *
         * Also the audit trail for a signature that did not verify — which is
         * either a misconfigured secret or somebody trying it on, and both are
         * worth being able to count.
         */
        Schema::create('repository_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('project_repository_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('delivery_id')->unique();
            $table->string('event', 64);
            $table->string('action', 64)->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->string('status', 24)->default('received');
            $table->string('error')->nullable();

            /*
             * A summary, never the payload.
             *
             * A `push` body carries every commit message and every author's
             * email address, and a `pull_request` body carries the whole diff
             * stat and the author's profile. None of it is needed after the
             * reducer has run, and all of it would sit in this table forever.
             */
            $table->jsonb('summary')->nullable();

            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'received_at']);
            $table->index(['project_repository_id', 'event']);
        });

        Schema::create('repository_pull_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_repository_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('number');
            $table->string('title');
            $table->string('author_login')->nullable();
            $table->string('head_branch');
            $table->string('base_branch');

            // open | merged | closed — "closed without merging" is a different
            // outcome from "merged", and a single boolean would lose it.
            $table->string('state', 16)->default('open');
            $table->boolean('draft')->default(false);

            // What the checks and the reviews say, as far as we have been told.
            // `unknown` is honest: a repository with no CI has no check state,
            // and reporting that as passing would be a lie about every PR.
            $table->string('checks_state', 16)->default('unknown');
            $table->string('review_state', 24)->default('none');
            $table->string('mergeable_state', 24)->nullable();

            $table->string('html_url');
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('updated_at_github')->nullable();
            $table->timestampTz('merged_at')->nullable();
            $table->string('merged_by')->nullable();
            $table->timestamps();

            $table->unique(['project_repository_id', 'number']);
            $table->index(['tenant_id', 'state']);
        });

        Schema::create('repository_workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_repository_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('run_id');
            $table->string('name');
            $table->string('event', 64)->nullable();
            $table->string('branch')->nullable();
            $table->string('commit_sha', 64)->nullable();

            // queued | in_progress | completed
            $table->string('status', 24)->default('queued');
            // success | failure | cancelled | skipped | timed_out | null
            $table->string('conclusion', 24)->nullable();

            /*
             * Which environment مهتم considers this run to be about, decided
             * from the branch mapping rather than from the workflow's name. A
             * workflow called "Deploy" that ran on a feature branch deployed
             * nothing.
             */
            $table->string('environment', 32)->nullable();

            $table->string('html_url');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['project_repository_id', 'run_id']);
            $table->index(['tenant_id', 'branch', 'status']);
        });

        /*
         * Deployments, which are not the same thing as a green build.
         *
         * The distinction this table exists for: a workflow that passed means
         * the code is good, a deployment that was created means something was
         * asked for, a deployment that succeeded means the server took it, and
         * a deployment that was *verified* means somebody got an answer out of
         * it over HTTP. Only the last one is "منشور", and the previous
         * deployment pipeline reported the first as if it were the last.
         */
        Schema::create('repository_deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_repository_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('deployment_id');
            $table->string('environment', 64);
            $table->string('ref')->nullable();
            $table->string('commit_sha', 64)->nullable();

            // pending | queued | in_progress | success | failure | error | inactive
            $table->string('state', 24)->default('pending');
            $table->string('description')->nullable();
            $table->string('html_url')->nullable();
            $table->string('environment_url')->nullable();

            // Separate from `state` on purpose: GitHub's `success` is the
            // deployer's own report, and nobody has yet asked the application.
            $table->string('verification_status', 24)->default('unverified');
            $table->timestampTz('verified_at')->nullable();

            $table->timestampTz('created_at_github')->nullable();
            $table->timestampTz('updated_at_github')->nullable();
            $table->timestamps();

            $table->unique(['project_repository_id', 'deployment_id']);
            $table->index(['tenant_id', 'environment', 'state']);
        });

        Schema::create('repository_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_repository_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('release_id');
            $table->string('tag_name');
            $table->string('name')->nullable();
            $table->boolean('draft')->default(false);
            $table->boolean('prerelease')->default(false);
            $table->string('commit_sha', 64)->nullable();
            $table->string('author_login')->nullable();
            $table->string('html_url');
            $table->timestampTz('published_at')->nullable();
            $table->timestamps();

            $table->unique(['project_repository_id', 'release_id']);
            $table->index(['tenant_id', 'published_at']);
        });

        /*
         * The timeline a project manager reads.
         *
         * Derived from the tables above rather than replacing them: those
         * answer "what is the state of X", this answers "what happened, in
         * order". Keeping both means the timeline can stay readable — one line
         * per thing worth knowing — without the state queries having to filter
         * noise out of a firehose.
         */
        Schema::create('repository_activity', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_repository_id')->constrained()->cascadeOnDelete();

            // commit | pr_opened | pr_merged | pr_closed | ci_passed | ci_failed
            // | deployed | deploy_failed | released
            $table->string('kind', 32);
            $table->string('title');
            $table->string('actor')->nullable();
            $table->string('ref')->nullable();
            $table->string('commit_sha', 64)->nullable();
            $table->string('url')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestampTz('happened_at');
            $table->timestamps();

            /*
             * One row per thing, however many times GitHub mentions it. A
             * merge arrives as `pull_request.closed` and again in the next
             * `push`, and a timeline that showed it twice would be the first
             * thing anybody complained about.
             */
            $table->unique(['project_repository_id', 'kind', 'commit_sha', 'ref'], 'repository_activity_unique_fact');
            $table->index(['tenant_id', 'happened_at']);
        });

        Schema::table('project_repositories', function (Blueprint $table) {
            /*
             * The webhook مهتم asked GitHub to create.
             *
             * The key is in the URL and is not a secret; the secret signs the
             * body and is stored encrypted because HMAC needs it back. (The
             * lead-source webhooks hash theirs — they compare a token the
             * caller sends, which is a different problem.)
             */
            $table->unsignedBigInteger('webhook_id')->nullable()->after('production_branch');
            $table->uuid('webhook_key')->nullable()->unique()->after('webhook_id');
            $table->text('webhook_secret')->nullable()->after('webhook_key');
            // ok | missing | manual | forbidden — "we were not allowed to create
            // it" is a different problem from "it is not there".
            $table->string('webhook_status', 24)->default('missing')->after('webhook_secret');
            $table->unsignedInteger('signature_failures')->default(0)->after('webhook_status');
        });
    }

    public function down(): void
    {
        Schema::table('project_repositories', function (Blueprint $table) {
            $table->dropColumn(['webhook_id', 'webhook_key', 'webhook_secret', 'webhook_status', 'signature_failures']);
        });

        Schema::dropIfExists('repository_activity');
        Schema::dropIfExists('repository_releases');
        Schema::dropIfExists('repository_deployments');
        Schema::dropIfExists('repository_workflow_runs');
        Schema::dropIfExists('repository_pull_requests');
        Schema::dropIfExists('repository_webhook_deliveries');
    }
};
