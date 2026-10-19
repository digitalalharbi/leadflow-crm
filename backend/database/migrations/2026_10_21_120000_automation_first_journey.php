<?php

use App\Domain\Automation\JourneyInstaller;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automation first, people by exception.
 *
 * Templates learn which project, language, stage and kind of buyer they are
 * for, so a journey can say "the welcome message" and get the right one for
 * each lead. A lead learns whether automation is still looking after it or has
 * handed it to a person, and why.
 *
 * Every existing workspace gets the journey installed, the same as a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
            $table->string('purpose', 32)->nullable()->after('channel');
            $table->string('language', 8)->nullable()->after('purpose');
            $table->string('stage', 32)->nullable()->after('language');
            $table->string('audience', 32)->nullable()->after('stage');
            $table->index(['tenant_id', 'channel', 'purpose']);
        });

        Schema::table('leads', function (Blueprint $table) {
            // null: automation never reached them · nurturing: automation is
            // talking to them · handed_off: a person's turn.
            $table->string('automation_state', 16)->nullable()->index();
            $table->string('needs_human_reason', 40)->nullable();
            $table->timestamp('handed_off_at')->nullable();
        });

        $context = app(TenantContext::class);

        Tenant::query()->each(function (Tenant $tenant) use ($context) {
            $context->withTenant($tenant, fn () => app(JourneyInstaller::class)->install($tenant));
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['automation_state', 'needs_human_reason', 'handed_off_at']);
        });

        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'channel', 'purpose']);
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn(['purpose', 'language', 'stage', 'audience']);
        });
    }
};
