<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real estate as a first-class model.
 *
 *   Workspace → Company → Project → Property (unit) → … → Reservation → Deal
 *
 * A project is the development; a property is one thing that can be sold or rented in it. A
 * lead says what they want (the facts below), a property says what it is, and the two are
 * compared by the matching engine. The facts are columns, not a bag of JSON, because every
 * report and every rule filters on them — and what is specific to one project goes through that
 * project's own questions (project_questions / lead_answers), which already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('code', 60);                     // the unit's own label: "A-204", "Villa 17"
            $table->string('title', 160)->nullable();
            $table->string('property_type', 24);            // apartment · villa · townhouse · duplex · land · office · shop · other
            $table->string('transaction_type', 8)->default('sale');   // sale · rent
            $table->unsignedBigInteger('price_minor')->nullable();     // minor units, like deal_value_minor
            $table->string('city', 120)->nullable();
            $table->string('district', 120)->nullable();
            $table->unsignedSmallInteger('bedrooms')->nullable();
            $table->unsignedSmallInteger('bathrooms')->nullable();
            $table->decimal('area_sqm', 10, 2)->nullable();
            $table->smallInteger('floor')->nullable();
            $table->string('status', 12)->default('available');        // available · reserved · sold · rented · hidden
            $table->json('features')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'project_id', 'code']);
            $table->index(['tenant_id', 'project_id', 'status']);
            $table->index(['tenant_id', 'transaction_type', 'property_type']);
            $table->index(['tenant_id', 'city', 'district']);
        });

        // A reservation holds a property for a lead — the step between a visit and a sale. It is
        // its own table because it has a lifecycle (it expires, is cancelled, becomes a deal) and
        // money (a deposit) that a lead's status cannot carry.
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 12)->default('pending');          // pending · confirmed · cancelled · expired · converted
            $table->unsignedBigInteger('amount_minor')->nullable();     // the deposit
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'lead_id']);
            $table->index(['tenant_id', 'project_id', 'status']);
        });

        Schema::table('leads', function (Blueprint $table) {
            // What the lead wants. `preferred_location` already holds the city; `city` is where
            // they live, which is not the same thing.
            $table->string('transaction_type', 8)->nullable()->after('purchase_timeline');      // buy · rent
            $table->string('preferred_district', 120)->nullable()->after('preferred_location');
            $table->unsignedSmallInteger('bedrooms')->nullable()->after('preferred_district');
            $table->decimal('area_sqm', 10, 2)->nullable()->after('bedrooms');
            $table->string('nationality', 60)->nullable()->after('area_sqm');
            $table->string('preferred_channel', 16)->nullable()->after('nationality');          // whatsapp · call · email
            $table->boolean('reservation_intent')->default(false)->after('appointment_interest');
            $table->foreignId('interested_property_id')->nullable()->after('project_id')->constrained('properties')->nullOnDelete();

            $table->index(['tenant_id', 'preferred_district']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'preferred_district']);
            $table->dropConstrainedForeignId('interested_property_id');
            $table->dropColumn(['transaction_type', 'preferred_district', 'bedrooms', 'area_sqm', 'nationality', 'preferred_channel', 'reservation_intent']);
        });

        Schema::dropIfExists('reservations');
        Schema::dropIfExists('properties');
    }
};
