<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The last line of the same invariant the model enforces.
 *
 * The model hook covers every path through the application. This covers the
 * ones that go around it: a raw query, an import written later, a fix applied
 * by hand in psql at 2am. The contradiction it prevents is cheap to create and
 * expensive to notice, because nothing errors — two screens simply disagree.
 *
 * Existing rows are backfilled first, to `updated_at` rather than `now()`: for
 * a lead that was marked contacted some time ago, the last time the row was
 * touched is a far better estimate of when that happened than today's date,
 * which would show up as a sub-second response time in every report.
 */
return new class extends Migration
{
    private const CONTACTED = ['contacted', 'replied', 'qualified', 'appointment', 'negotiation', 'won'];

    public function up(): void
    {
        $list = "'".implode("','", self::CONTACTED)."'";

        DB::statement("
            update leads
               set first_contact_at = coalesce(updated_at, created_at)
             where first_contact_at is null
               and status in ({$list})
        ");

        DB::statement("
            alter table leads
              add constraint leads_contacted_requires_contact_time
              check (status not in ({$list}) or first_contact_at is not null)
        ");
    }

    public function down(): void
    {
        DB::statement('alter table leads drop constraint if exists leads_contacted_requires_contact_time');
    }
};
