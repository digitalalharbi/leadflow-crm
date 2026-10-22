<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An action a rule asked for later.
 *
 * Automation was entirely immediate: a rule ran the instant its event arrived
 * and had no way to say "and then, two days from now". That left the most
 * ordinary follow-up sequence in the business unwritable — "if nobody has
 * replied in two days, send a WhatsApp" — because nothing in the system ever
 * fires two days after something did not happen.
 *
 * A row per waiting action rather than a bare queued job, for three reasons a
 * job alone cannot serve: the trace has to show what is still coming, closing a
 * lead has to be able to cancel it, and a worker that dies must not lose it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();

            // The rule may be deleted while an action of its is still waiting;
            // the action still ran, and the trace still has to be readable.
            $table->foreignId('automation_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rule_name');

            $table->jsonb('action');
            $table->timestamp('run_at');

            // pending | done | cancelled | failed
            $table->string('status', 16)->default('pending');
            $table->text('outcome')->nullable();
            $table->timestamp('resolved_at')->nullable();

            /*
             * The correlation id of the run that scheduled it, so a manager
             * reading "a WhatsApp went out on Thursday" can get back to the
             * event on Tuesday that asked for it.
             */
            $table->uuid('correlation_id')->nullable();

            $table->timestamps();

            $table->index(['status', 'run_at']);
            $table->index(['tenant_id', 'lead_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_actions');
    }
};
