<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The record of every WhatsApp alert to a member of staff: sent, failed, or held back (and why).
 * It is the audit trail, the rate limiter's evidence and the dedup's memory in one table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_alert_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 32);
            $table->string('dedup_key', 190);
            $table->string('title', 160);
            // queued · sent · failed · skipped
            $table->string('status', 12)->default('queued');
            // For a skipped or failed one: quiet_hours · rate_limited · duplicate · no_phone · not_connected · opted_out · provider_error · undeliverable
            $table->string('reason', 32)->nullable();
            $table->string('provider', 32)->nullable();
            $table->string('provider_message_id', 120)->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->index(['tenant_id', 'user_id', 'dedup_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_alert_logs');
    }
};
