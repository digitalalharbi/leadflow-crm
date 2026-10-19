<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A one-time key to approve or reject one proposal, sent to one person.
 *
 * Only the hash is stored: the link in somebody's WhatsApp is the only copy of
 * the token. It is bound to that person and that proposal, expires in a day,
 * and works once — opening it shows the proposal, and only pressing a button
 * on that page decides it, so a link preview or a scanner cannot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('action_proposal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('channel', 16)->default('whatsapp');
            // sent | failed — whether the message carrying it was accepted.
            $table->string('delivery', 16)->nullable();
            $table->text('delivery_error')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('used_decision', 8)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_tokens');
    }
};
