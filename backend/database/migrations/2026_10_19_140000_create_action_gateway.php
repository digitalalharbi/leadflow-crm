<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nothing touches an ad account without a person saying yes.
 *
 *   client_rules       what this client wants and will not tolerate — targets,
 *                      ceilings, how far a budget may move, the days nothing
 *                      changes. Every agent reads them before it decides; the
 *                      gateway enforces them in code before anything runs.
 *   action_proposals   a change somebody (an agent or a person) wants made,
 *                      with why and what it should do, waiting for a decision.
 *   action_log         what was actually sent to the platform and what came
 *                      back. Append-only: it is the audit trail.
 *
 * There is no delete action anywhere in this vocabulary. The strongest thing
 * the system can do to a campaign is pause it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Null project = the workspace's defaults; a project's own row wins.
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('target_cpl_minor')->nullable();
            $table->unsignedBigInteger('target_cpql_minor')->nullable();
            $table->unsignedBigInteger('target_cpa_minor')->nullable();
            $table->decimal('target_roas', 8, 2)->nullable();
            // Below this spend a campaign is not judged at all.
            $table->unsignedBigInteger('min_spend_to_judge_minor')->nullable();
            $table->unsignedBigInteger('daily_budget_ceiling_minor')->nullable();
            $table->unsignedSmallInteger('max_budget_change_pct')->default(20);
            // ISO dates and/or weekday names ("friday") on which nothing changes.
            $table->jsonb('blackout_days')->nullable();
            $table->jsonb('forbidden_words')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('create unique index client_rules_one_per_scope on client_rules (tenant_id, coalesce(project_id, 0))');

        Schema::create('action_proposals', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ad_set_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ad_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 24);
            $table->string('action', 32);
            $table->jsonb('payload')->nullable();
            $table->text('reason');
            $table->text('expected_impact')->nullable();
            // agent | user
            $table->string('proposed_by', 8);
            $table->string('agent', 32)->nullable();
            $table->foreignId('proposed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // pending | approved | rejected | executing | executed | failed | blocked
            $table->string('status', 16)->default('pending');
            // What the policy said when the proposal was made (warnings, blocks).
            $table->jsonb('policy')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->timestamp('executed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'created_at']);
        });

        Schema::create('action_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('action_proposal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 24);
            $table->string('action', 32);
            $table->jsonb('request')->nullable();
            $table->jsonb('response')->nullable();
            $table->boolean('ok');
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_log');
        Schema::dropIfExists('action_proposals');
        Schema::dropIfExists('client_rules');
    }
};
