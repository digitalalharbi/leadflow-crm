<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened when somebody pressed "commission".
 *
 * Commissioning is the step between "credentials entered" and "a real event
 * arrived": it uses the credentials once, against the real provider, and says
 * which part worked. It is kept as a row rather than shown and forgotten, for
 * the same reason a deploy is: the question is never only "does it work" but
 * "when did somebody last check, and what did it say" — and the answer to the
 * second is what a person reads when the first one has gone quiet.
 *
 * Steps, not a transcript. Each is a name, a verdict and a short sentence the
 * provider itself supplied; nothing in it is a credential or a customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissioning_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ran_by')->nullable()->constrained('users')->nullOnDelete();

            // google | whatsapp | twilio | ads
            $table->string('key', 32);
            $table->boolean('ok');
            $table->jsonb('steps');

            $table->timestamps();

            $table->index(['tenant_id', 'key', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissioning_runs');
    }
};
