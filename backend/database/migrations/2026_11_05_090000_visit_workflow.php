<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A visit has a life: asked for, confirmed, moved, done or missed — and, once done, a result.
 *
 * Until now an appointment was `scheduled` and then one of three endings. A customer asking to
 * come and see a unit is not yet an appointment (nobody has said when), and a visit that happened
 * is only useful if somebody says what came of it — interested, needs a follow-up, wants to
 * reserve, or is gone. The columns here carry both.
 *
 * `scheduled_at` may now be empty: a *requested* visit has no time until a person agrees one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->change();
            $table->string('status', 20)->default('confirmed')->change();

            $table->string('source', 20)->default('rep')->after('status');              // rep · customer · automation
            $table->foreignId('project_id')->nullable()->after('lead_id')->constrained()->nullOnDelete();
            $table->foreignId('property_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('no_show_at')->nullable();
            $table->unsignedSmallInteger('rescheduled_count')->default(0);
            $table->string('outcome', 20)->nullable();                                   // interested · follow_up · reservation · lost
            $table->timestamp('outcome_at')->nullable();

            $table->index(['tenant_id', 'status', 'requested_at'], 'appointments_requested_index');
        });

        // Everything booked so far was agreed by a person: it is confirmed.
        DB::table('appointments')->where('status', 'scheduled')->update(['status' => 'confirmed', 'confirmed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        DB::table('appointments')->whereIn('status', ['confirmed', 'rescheduled', 'requested'])->update(['status' => 'scheduled']);

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_requested_index');
            $table->dropConstrainedForeignId('property_id');
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn(['source', 'requested_at', 'confirmed_at', 'cancelled_at', 'no_show_at', 'rescheduled_count', 'outcome', 'outcome_at']);
            $table->string('status', 20)->default('scheduled')->change();
        });
    }
};
