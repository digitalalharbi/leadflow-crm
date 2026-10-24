<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One short-link layer for everything Mohtam hands to people outside the workspace.
 *
 * `short_links` holds what every external link needs — a high-entropy token (stored hashed, and encrypted so its owner can
 * copy it again), the workspace and project it belongs to, who made it, when it expires, whether it is switched on,
 * an optional password, and how often it was opened. A report is the first thing to use it.
 *
 * Every existing report link is moved here with its own token, so a link already sent keeps opening. The columns it leaves
 * behind on `report_shares` stay in place (made optional) and are no longer read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('short_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);                                    // report · form · tracking · invitation · share
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('label', 120)->nullable();
            $table->string('client_name', 160)->nullable();
            $table->string('client_email', 190)->nullable();

            $table->string('token_hash', 64)->unique();
            $table->text('token_enc')->nullable();
            $table->string('password_hash')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();

            // Optional: only these people may open it, after a code sent to their email.
            $table->boolean('require_otp')->default(false);
            $table->json('allowed_emails')->nullable();
            $table->json('allowed_domains')->nullable();
            $table->boolean('allow_export')->default(false);

            $table->timestamps();

            $table->index(['tenant_id', 'type', 'resource_id']);
            $table->index(['tenant_id', 'revoked_at']);
        });

        Schema::table('report_share_views', function (Blueprint $table) {
            $table->foreignId('short_link_id')->nullable()->after('report_share_id')->constrained('short_links')->nullOnDelete();
        });

        // The old columns stop being required; nothing reads them any more.
        foreach (['token_hash', 'token_enc'] as $column) {
            DB::statement("alter table report_shares alter column {$column} drop not null");
        }

        $now = now();

        DB::table('report_shares')->orderBy('id')->each(function ($share) use ($now) {
            if ($share->token_hash === null) {
                return;
            }

            $id = DB::table('short_links')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $share->tenant_id,
                'type' => 'report',
                'resource_id' => $share->id,
                'project_id' => $share->project_id,
                'created_by' => $share->created_by,
                'token_hash' => $share->token_hash,
                'token_enc' => $share->token_enc,
                'password_hash' => $share->password_hash,
                'is_active' => true,
                'expires_at' => $share->expires_at,
                'revoked_at' => $share->revoked_at,
                'view_count' => $share->view_count,
                'last_viewed_at' => $share->last_viewed_at,
                'created_at' => $share->created_at ?? $now,
                'updated_at' => $now,
            ]);

            DB::table('report_share_views')->where('report_share_id', $share->id)->update(['short_link_id' => $id]);
        });
    }

    public function down(): void
    {
        Schema::table('report_share_views', function (Blueprint $table) {
            $table->dropConstrainedForeignId('short_link_id');
        });

        Schema::dropIfExists('short_links');
    }
};
