<?php

use App\Domain\Automation\JourneyInstaller;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Migrations\Migration;

/** The journey installer again, for the four messages of the interested journey. Idempotent: a message a workspace already has is left alone. */
return new class extends Migration
{
    public function up(): void
    {
        $context = app(TenantContext::class);

        Tenant::query()->each(fn (Tenant $tenant) => $context->withTenant($tenant, fn () => app(JourneyInstaller::class)->install($tenant)));
    }

    public function down(): void {}
};
