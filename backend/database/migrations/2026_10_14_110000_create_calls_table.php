<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A call, as far as مهتم can know about it.
 *
 * Today that is: a rep pressed the button, and later said what happened. There
 * is no telephony provider connected, and the product is explicit about that
 * rather than pretending — `provider` is `manual`, duration is null, and no
 * screen claims a number it does not have.
 *
 * The columns a provider would fill are here from the start because the shape
 * of the record is the same either way: the same lead, the same rep, the same
 * outcome. What a provider adds is `external_id`, `status` moving on its own,
 * and `duration_seconds` — not a different table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            // Who placed it. Kept when the account is deleted — a call happened,
            // and the history of a lead should not change because somebody left.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // manual = the rep's own phone. Anything else is a connected provider.
            $table->string('provider', 32)->default('manual');
            $table->string('external_id')->nullable();
            $table->string('direction', 16)->default('outbound');

            /*
             * initiated is where a manual call stays until the rep says
             * otherwise: nothing watches a rep's phone, and a status that
             * advanced on its own would be a guess presented as a fact.
             */
            $table->string('status', 24)->default('initiated');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('outcome', 32)->nullable();

            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'started_at']);
            $table->index(['lead_id', 'started_at']);
            $table->unique(['provider', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
