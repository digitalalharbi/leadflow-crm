<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a lead did, as opposed to what we did to them.
 *
 * The timeline already records every action the team took. This records the
 * other half — the customer opening a brochure, replying, clicking a link —
 * which is the half that says whether any of it worked.
 *
 * Kept as events rather than counters on the lead because the question is
 * usually "recently": a brochure opened twice last March is history, and the
 * same two opens this morning is a phone call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_engagements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();

            // file_opened | email_opened | link_clicked | whatsapp_replied
            $table->string('type', 32);
            $table->jsonb('meta')->nullable();
            $table->timestampTz('happened_at');
            $table->timestamps();

            $table->index(['tenant_id', 'lead_id', 'happened_at']);
            $table->index(['lead_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_engagements');
    }
};
