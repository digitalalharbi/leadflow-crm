<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every address a page has ever held, the one it holds now among them. One table with one unique index: taking an address and
 * remembering an old one meet there, so two companies cannot end up with the same address however close together they ask.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('landing_slug_history', 'landing_slug_claims');

        DB::table('landing_slug_claims')->insertOrIgnore(
            DB::table('landing_forms')->whereNotNull('slug')->get(['slug', 'tenant_id', 'id'])
                ->map(fn ($form) => ['slug' => $form->slug, 'tenant_id' => $form->tenant_id, 'landing_form_id' => $form->id, 'created_at' => now(), 'updated_at' => now()])
                ->all()
        );
    }

    public function down(): void
    {
        DB::table('landing_slug_claims')->whereIn('slug', DB::table('landing_forms')->whereNotNull('slug')->select('slug'))->delete();

        Schema::rename('landing_slug_claims', 'landing_slug_history');
    }
};
