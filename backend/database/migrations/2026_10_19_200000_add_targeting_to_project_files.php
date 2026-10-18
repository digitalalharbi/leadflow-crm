<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a file is for, beyond what language it is in.
 *
 * Until now the chooser could tell a brochure from a deck and an Arabic file
 * from an English one. A real project also has the brochure made for one
 * campaign, the price sheet that only makes sense once somebody is qualified,
 * and the deck for investors. Each of those is a fact about the *file*, set when
 * it is uploaded, so that choosing is a matter of matching and not of a rep
 * remembering.
 *
 * Every column is optional, and null always means "anybody" — a file that was
 * uploaded before this existed is chosen exactly as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_files', function (Blueprint $table) {
            // The campaign this was made for, matched against the lead's own.
            $table->string('campaign')->nullable()->after('language');
            // The stages it is appropriate in; null is every stage.
            $table->json('stages')->nullable()->after('campaign');
            // investor | end_user | financing — who it speaks to.
            $table->string('audience', 20)->nullable()->after('stages');
        });
    }

    public function down(): void
    {
        Schema::table('project_files', function (Blueprint $table) {
            $table->dropColumn(['campaign', 'stages', 'audience']);
        });
    }
};
