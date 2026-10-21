<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every email the product sends on its own account: what, to whom, by which provider, how it went.
 * A row exists before anything is sent (so a send that dies is a visible queued email, not a person
 * who got something we have no record of), and every attempt is kept — the failure log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24)->default('report');                 // report · statement · digest · other
            $table->string('to_email', 190);
            $table->string('to_name', 160)->nullable();
            $table->string('subject', 255);
            $table->longText('html');
            $table->longText('text')->nullable();
            $table->string('provider', 16);                                // the provider chosen when it was queued
            $table->string('status', 10)->default('queued');               // queued · sending · sent · logged · failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(4);
            $table->string('provider_message_id', 190)->nullable();
            $table->text('last_error')->nullable();
            $table->string('subject_type', 64)->nullable();                // what it is about: a report share
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });

        Schema::create('email_delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_delivery_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt');
            $table->string('provider', 16);
            $table->boolean('ok');
            $table->boolean('retryable')->default(false);
            $table->text('error')->nullable();
            $table->string('provider_message_id', 190)->nullable();
            $table->timestamp('attempted_at')->useCurrent();

            $table->index(['email_delivery_id', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_delivery_attempts');
        Schema::dropIfExists('email_deliveries');
    }
};
