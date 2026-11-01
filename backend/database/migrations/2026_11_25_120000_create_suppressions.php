<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company's do-not-contact list, by address and number rather than by lead: someone who asked to stop is not
 * messaged again because they filled in a second form. Applied when a lead is created and when an entry is added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('value', 191);
            $table->string('reason', 32);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'channel', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressions');
    }
};
