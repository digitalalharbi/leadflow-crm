<?php

use App\Domain\Automation\JourneyInstaller;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Migrations\Migration;

/** The journey installer again, for the nurture message it now also provides. Idempotent. */
return new class extends Migration
{
    public function up(): void
    {
        $context = app(TenantContext::class);

        Tenant::query()->each(fn (Tenant $tenant) => $context->withTenant($tenant, fn () => app(JourneyInstaller::class)->install($tenant)));
    }

    public function down(): void {}
};
