<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The agents, what they run on a schedule, and what they said.
 *
 * An agent is fixed instructions + a closed set of tools + the client's own
 * rules. It reads and it advises; it never writes to an ad account — its only
 * way to change anything is to file a proposal through the action gateway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // orchestrator | campaign | analyst | creative | leads | reports
            $table->string('key', 24);
            $table->string('name');
            $table->text('role');
            $table->string('model', 64)->nullable();
            $table->jsonb('tools')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('routines', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 32);
            $table->string('agent', 24);
            $table->string('cron', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            // ok | failed
            $table->string('last_status', 16)->nullable();
            $table->text('last_summary')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('agent_threads', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('agent', 24);
            $table->string('routine', 32)->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->timestamps();

            $table->index(['tenant_id', 'agent', 'created_at']);
        });

        Schema::create('agent_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_thread_id')->constrained()->cascadeOnDelete();
            // agent | user | tool
            $table->string('role', 8);
            $table->string('agent', 24)->nullable();
            $table->text('content');
            // Structured findings, figures, proposal ids — what the text is about.
            $table->jsonb('data')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_messages');
        Schema::dropIfExists('agent_threads');
        Schema::dropIfExists('routines');
        Schema::dropIfExists('agents');
    }
};
