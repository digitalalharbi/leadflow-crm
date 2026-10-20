<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a conversation says, as structured facts and nothing else.
 *
 * A fact is a kind and a value from a closed list (intent: ready_to_buy,
 * objection: price_high …) with, where it has one, small structured detail
 * (a budget's range). The words themselves are never copied here — a fact
 * points at the message it came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('value', 48);
            $table->json('detail')->nullable();
            $table->unsignedTinyInteger('confidence')->default(60);
            // claude | rules — and never anything else, so the screen can always say which.
            $table->string('source', 16);
            $table->foreignId('message_id')->nullable()->constrained('lead_messages')->nullOnDelete();
            $table->timestamp('extracted_at');
            $table->timestamps();

            $table->unique(['lead_id', 'kind', 'value']);
            $table->index(['tenant_id', 'kind', 'value']);
        });

        Schema::table('leads', function (Blueprint $table) {
            // The last inbound message the facts were read from, how, and why that way.
            $table->unsignedBigInteger('facts_message_id')->nullable();
            $table->string('facts_engine', 16)->nullable();
            $table->string('facts_note', 32)->nullable();
            $table->timestamp('facts_at')->nullable();
        });

        Schema::table('lead_messages', function (Blueprint $table) {
            // What a message was for: the project questions a qualification message asked, in order.
            $table->json('context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lead_messages', fn (Blueprint $t) => $t->dropColumn('context'));
        Schema::table('leads', fn (Blueprint $t) => $t->dropColumn(['facts_message_id', 'facts_engine', 'facts_note', 'facts_at']));
        Schema::dropIfExists('lead_facts');
    }
};
