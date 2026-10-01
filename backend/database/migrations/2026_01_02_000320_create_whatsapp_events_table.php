<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One row per webhook delivery we have already processed, and one row per
         * inbound message we could not attach to a lead.
         *
         * The first makes the endpoint idempotent — Meta retries until it sees a
         * 200, and a slow response must not duplicate a conversation. The second
         * is the audit trail for a message from a number nobody recognises: the
         * first release deliberately does not invent a lead from it.
         */
        Schema::create('whatsapp_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->nullable()->constrained()->nullOnDelete();

            $table->string('event_id');                 // provider message/status id
            $table->string('event_type', 32);           // message | status | unmatched | rejected
            $table->string('phone_number_id')->nullable();
            $table->string('from_phone', 32)->nullable();
            $table->string('outcome', 32)->nullable();
            $table->text('detail')->nullable();
            $table->jsonb('payload')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'event_type', 'event_id'], 'whatsapp_events_dedupe_unique');
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_events');
    }
};
