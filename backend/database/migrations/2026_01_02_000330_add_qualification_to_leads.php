<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            /*
             * How much we know about the lead, kept apart from the pipeline stage.
             * A contacted lead can still be unqualified, and a qualified lead can
             * be lost — collapsing the two would lose both facts.
             */
            $table->string('qualification_status', 24)->default('unqualified')->after('temperature');
            $table->timestamp('qualified_at')->nullable()->after('qualification_status');
            $table->boolean('appointment_interest')->nullable()->after('purchase_timeline');

            // Response metrics. first_contact_at is when we reached out;
            // first_response_at is when they answered.
            $table->timestamp('first_contact_at')->nullable()->after('last_contact_at');
            $table->timestamp('first_response_at')->nullable()->after('first_contact_at');

            // Consent. The product does not claim to guarantee compliance, but a
            // customer who asks to stop must be able to, and that must be legible.
            $table->timestamp('whatsapp_opt_in_at')->nullable()->after('first_response_at');
            $table->timestamp('whatsapp_opt_out_at')->nullable()->after('whatsapp_opt_in_at');

            $table->index(['tenant_id', 'qualification_status'], 'leads_tenant_qualification_index');
        });

        /*
         * "Nobody has touched this yet" — the query behind the neglected-leads
         * panel. Partial, because it only ever looks at new, undeleted leads,
         * which is a shrinking fraction of a busy tenant's table.
         */
        DB::statement(<<<'SQL'
            CREATE INDEX leads_awaiting_first_contact_index
            ON leads (tenant_id, created_at)
            WHERE first_contact_at IS NULL AND status = 'new' AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS leads_awaiting_first_contact_index');

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_tenant_qualification_index');
            $table->dropColumn([
                'qualification_status', 'qualified_at', 'appointment_interest',
                'first_contact_at', 'first_response_at',
                'whatsapp_opt_in_at', 'whatsapp_opt_out_at',
            ]);
        });
    }
};
