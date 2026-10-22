<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How long an interested lead may wait for a person — one line per level.
 *
 * A new lead's first-contact target already exists (`first_contact_target_minutes`). What it does
 * not say is that a lead who has just asked to RESERVE a unit must be answered in minutes, one
 * who asked for a visit within half an hour, a hot one within a quarter, an interested one within
 * the hour. Null means "the product's default", like every other line.
 *
 * `last_customer_message_at` is what starts that clock again: the lead's level may not have
 * changed, but they have just written, and somebody owes them an answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->unsignedInteger('sla_hot_minutes')->nullable();
            $table->unsignedInteger('sla_interested_minutes')->nullable();
            $table->unsignedInteger('sla_visit_minutes')->nullable();
            $table->unsignedInteger('sla_reservation_minutes')->nullable();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('last_customer_message_at')->nullable()->after('first_response_at');
            $table->index(['tenant_id', 'last_customer_message_at']);
        });

        // What is already there: the newest thing each customer wrote.
        DB::statement("update leads set last_customer_message_at = m.at from (select lead_id, max(created_at) as at from lead_messages where direction = 'inbound' group by lead_id) m where m.lead_id = leads.id");
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'last_customer_message_at']);
            $table->dropColumn('last_customer_message_at');
        });

        Schema::table('tenant_settings', fn (Blueprint $t) => $t->dropColumn(['sla_hot_minutes', 'sla_interested_minutes', 'sla_visit_minutes', 'sla_reservation_minutes']));
    }
};
