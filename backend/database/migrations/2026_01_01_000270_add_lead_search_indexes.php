<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Name and email search uses a leading-wildcard ILIKE, which no btree index can
 * serve — at 10k leads it is a sequential scan, and it only gets worse.
 *
 * pg_trgm makes those searches index-backed. The extension needs privileges that
 * some managed PostgreSQL plans withhold, so this degrades to the existing
 * behaviour rather than failing the deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->trigramsAvailable()) {
            return;
        }

        DB::statement('CREATE INDEX IF NOT EXISTS leads_full_name_trgm_index ON leads USING gin (full_name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS leads_email_trgm_index ON leads USING gin (email gin_trgm_ops)');

        // Partial: the only rows the overdue views care about are open ones.
        DB::statement(
            "CREATE INDEX IF NOT EXISTS leads_open_follow_up_index
             ON leads (tenant_id, next_follow_up_at)
             WHERE next_follow_up_at IS NOT NULL AND status NOT IN ('won', 'lost') AND deleted_at IS NULL"
        );
    }

    public function down(): void
    {
        foreach (['leads_full_name_trgm_index', 'leads_email_trgm_index', 'leads_open_follow_up_index'] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
    }

    private function trigramsAvailable(): bool
    {
        if (! Schema::hasTable('leads')) {
            return false;
        }

        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

            return true;
        } catch (Throwable $e) {
            // Logged rather than thrown: search still works, just unindexed.
            logger()->warning('pg_trgm unavailable; lead search will not be index-backed.', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
};
