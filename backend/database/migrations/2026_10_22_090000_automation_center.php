<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The automation centre: one row per thing automation did (or decided not to,
 * or is waiting to do), rules that can watch or recommend instead of act, a
 * score a rule can actually move, and scoring a project can tune.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_action_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('automation_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('scheduled_action_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rule_name', 160)->nullable();
            $table->string('trigger', 48)->nullable();
            $table->string('action_type', 40);
            $table->string('channel', 16)->nullable();
            $table->string('mode', 12)->default('auto');
            // completed · waiting · failed · skipped · needs_human
            $table->string('result', 16);
            $table->string('before', 120)->nullable();
            $table->string('after', 120)->nullable();
            $table->text('description')->nullable();
            $table->timestamp('happened_at');
            $table->timestamps();

            $table->index(['tenant_id', 'happened_at']);
            $table->index(['tenant_id', 'result']);
            $table->index(['tenant_id', 'action_type']);
        });

        Schema::table('automation_rules', function (Blueprint $table) {
            // auto: do it · recommend: tell a person what it would do · monitor: only record it.
            $table->string('mode', 12)->default('auto')->after('is_enabled');
        });

        Schema::table('leads', function (Blueprint $table) {
            // What rules added to or took from the score, kept apart from the
            // facts so re-scoring from the facts does not erase it.
            $table->smallInteger('score_adjustment')->default(0);
            // Which "no reply after N minutes" moments have already fired, and
            // for which message — so each fires once per message.
            $table->json('no_reply_state')->nullable();
        });

        Schema::table('projects', function (Blueprint $table) {
            // Engagement weights and temperature thresholds this project uses
            // instead of the workspace defaults.
            $table->json('scoring')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn('scoring'));
        Schema::table('leads', fn (Blueprint $t) => $t->dropColumn(['score_adjustment', 'no_reply_state']));
        Schema::table('automation_rules', fn (Blueprint $t) => $t->dropColumn('mode'));
        Schema::dropIfExists('automation_action_logs');
    }
};
