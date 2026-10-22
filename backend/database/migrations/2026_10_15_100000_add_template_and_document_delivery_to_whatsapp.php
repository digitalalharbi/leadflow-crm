<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A saved message can also be a Meta-approved template.
         *
         * One row, two ways of delivering it: inside the customer's 24-hour
         * window the `body` goes out as plain text, and outside it the same
         * message goes as the template Meta approved. Keeping them on one row is
         * what stops a workspace maintaining the wording twice and the two
         * drifting apart.
         */
        Schema::table('message_templates', function (Blueprint $table) {
            $table->string('meta_template_name', 512)->nullable()->after('body');
            $table->string('meta_language', 16)->nullable()->after('meta_template_name');
            // Ordered list of the lead fields that fill {{1}}, {{2}}, … in the
            // approved template: ["name", "project"].
            $table->json('meta_parameters')->nullable()->after('meta_language');
        });

        /*
         * How to send this message again.
         *
         * The retry job used to resend `body` as text, which was right while
         * text was the only kind. A template retried as text is refused outside
         * the window and, inside it, goes out with different words than the
         * ones that were approved; a brochure retried as text sends its caption
         * and no file. The recipe is what makes a retry the same message.
         */
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->json('send_spec')->nullable()->after('provider_payload');
        });
    }

    public function down(): void
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn('send_spec');
        });

        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn(['meta_template_name', 'meta_language', 'meta_parameters']);
        });
    }
};
