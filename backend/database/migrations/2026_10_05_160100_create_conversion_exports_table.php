<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales outcomes queued to go back to the ad platform that produced them.
 *
 * This is the half of the loop that makes the spend optimisable: the platform
 * learns which clicks became buyers, not merely which became form fills, and
 * starts finding more of the former. Without it the optimiser is being told
 * that every lead is equally good, which is the thing the whole product exists
 * to disprove.
 *
 * A row is created the moment the outcome happens and sent later. That ordering
 * matters: a workspace that connects Meta next month should be able to send
 * last month's conversions, and a platform that is down for an hour must not
 * cost anybody a sale record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversion_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();

            $table->string('platform', 32);
            $table->string('event', 32);
            $table->decimal('value', 12, 2)->nullable();
            $table->string('currency', 3)->default('SAR');
            $table->timestamp('occurred_at');

            /*
             * `awaiting_credentials` is a status of its own rather than a
             * failure. A workspace that has not connected Meta has not done
             * anything wrong, and burning retries against an integration that
             * was never configured turns a setup step into an alert storm.
             */
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();

            /** What was actually sent, minus anything that identifies a person. */
            $table->json('request_summary')->nullable();

            $table->timestamps();

            // One export per outcome per platform: a lead that is won, reopened
            // and won again should not be counted twice by the optimiser.
            $table->unique(['tenant_id', 'lead_id', 'platform', 'event'], 'conversion_exports_unique_outcome');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_exports');
    }
};
