<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The identifiers an ad platform needs to recognise its own click.
 *
 * Campaign and ad names tell a human which campaign a lead came from. They tell
 * Meta nothing: to credit a sale back to the click that produced it, the
 * platform wants the click's own id, and without one the best any offline
 * conversion upload can do is match on a hashed phone number and hope.
 *
 * The UTM set is kept separately from the platform's click id because they
 * answer different questions — the click id is for the platform's attribution,
 * the UTMs are for the workspace's own reporting, and a landing page often
 * carries one without the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // One column rather than four (gclid/fbclid/ttclid/sccid): a lead
            // comes from one platform, and which one is already in `platform`.
            $table->string('click_id', 512)->nullable()->after('form_id');

            $table->string('utm_source', 255)->nullable()->after('click_id');
            $table->string('utm_medium', 255)->nullable()->after('utm_source');
            $table->string('utm_campaign', 255)->nullable()->after('utm_medium');
            $table->string('utm_term', 255)->nullable()->after('utm_campaign');
            $table->string('utm_content', 255)->nullable()->after('utm_term');

            $table->index(['tenant_id', 'utm_campaign']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'utm_campaign']);
            $table->dropColumn([
                'click_id', 'utm_source', 'utm_medium',
                'utm_campaign', 'utm_term', 'utm_content',
            ]);
        });
    }
};
