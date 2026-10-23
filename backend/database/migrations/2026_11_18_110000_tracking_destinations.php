<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where events can go, and what was sent.
 *
 *   tracking_destinations   a place (GA4, Meta, a GTM container, a webhook…) with its public identifier, its encrypted
 *                           credential, the events it may be told about and whether it may see a hashed identifier
 *   destination_deliveries  the outbox: one row per event per destination, unique on (destination, event id) so a
 *                           retry, a replay or a platform redelivery is never a second event
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_destinations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();   // null: the whole workspace
            $table->string('kind', 24);
            $table->string('name', 120);
            $table->boolean('is_enabled')->default(false);
            $table->json('config')->nullable();                                           // public identifiers
            $table->text('credentials')->nullable();                                      // encrypted
            $table->json('events')->nullable();                                           // the allowlist of canonical event names
            $table->boolean('send_value')->default(true);                                 // revenue figures
            $table->boolean('hash_identifiers')->default(true);                           // a hashed email or phone, with consent
            $table->string('consent_mode', 12)->default('required');                     // required · unless_denied
            $table->timestamp('last_event_at')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'is_enabled']);
            $table->index(['tenant_id', 'project_id']);
        });

        Schema::create('destination_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained('tracking_destinations')->cascadeOnDelete();
            $table->foreignId('tracking_event_id')->nullable()->constrained('tracking_events')->nullOnDelete();
            $table->string('event_id', 40);
            $table->string('event_name', 40);
            // pending · sent · failed · skipped · awaiting_credentials
            $table->string('status', 24)->default('pending');
            $table->string('reason', 40)->nullable();          // why it was skipped: consent_missing, not_allowed, no_identifier…
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->json('request_summary')->nullable();       // what was sent, with every identifier removed
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['destination_id', 'event_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'destination_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destination_deliveries');
        Schema::dropIfExists('tracking_destinations');
    }
};
