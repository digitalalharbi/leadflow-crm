<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an email needs that a WhatsApp message does not.
 *
 * `lead_messages` was channel-agnostic by design, so an email is a row in it.
 * It adds only what the channel itself requires: a subject; a token that names
 * one message in the open pixel, the click redirect and the unsubscribe link —
 * a token and not an id, so a customer cannot walk the sequence; and the lead's
 * own opt-out, which is per channel because "stop WhatsApp" is not "stop email".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->string('subject', 255)->nullable()->after('template_name');
            $table->string('tracking_token', 40)->nullable()->unique()->after('subject');
        });

        Schema::table('message_templates', function (Blueprint $table) {
            $table->string('subject', 255)->nullable()->after('name');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('email_opt_out_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', fn (Blueprint $t) => $t->dropColumn('email_opt_out_at'));
        Schema::table('message_templates', fn (Blueprint $t) => $t->dropColumn('subject'));
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn(['subject', 'tracking_token']);
        });
    }
};
