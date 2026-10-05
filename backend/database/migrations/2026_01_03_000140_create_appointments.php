<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A meeting with a lead.
 *
 * The `appointment` stage already existed, but only as a label — there was
 * nowhere to record when the meeting is, who is taking it, or whether it
 * happened. A pipeline that says "appointment" and cannot say "at four on
 * Tuesday" is a status, not a calendar, and the rep keeps that part in their
 * head or their phone.
 *
 * Shaped so a calendar provider can be added later without a migration:
 * `external_id` and `external_provider` are the seam, nullable until one is
 * connected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();

            // The rep taking the meeting. Kept if they leave, so history stays
            // readable; the lead's own assignment is a separate question.
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes')->default(60);

            // scheduled | completed | cancelled | no_show
            $table->string('status', 20)->default('scheduled');

            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->text('outcome_note')->nullable();

            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // The seam for Google or Microsoft calendar, later.
            $table->string('external_provider', 30)->nullable();
            $table->string('external_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The agenda query: one rep's upcoming meetings, soonest first.
            $table->index(['tenant_id', 'assigned_to', 'status', 'scheduled_at'], 'appointments_agenda_index');
            // The reminder sweep: anything scheduled and not yet reminded.
            $table->index(['tenant_id', 'status', 'scheduled_at'], 'appointments_sweep_index');
            $table->index(['lead_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
