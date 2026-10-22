<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A conversation's messages are always read by thread, and a thread is deleted with its messages;
 * the table had no index on the thread, so both walked every message of every workspace.
 *
 * Additive only: nothing is changed or dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_messages', function (Blueprint $table) {
            $table->index(['agent_thread_id', 'id'], 'agent_messages_thread_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('agent_messages', function (Blueprint $table) {
            $table->dropIndex('agent_messages_thread_id_index');
        });
    }
};
