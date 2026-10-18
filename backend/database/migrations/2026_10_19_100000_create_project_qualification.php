<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a project asks, and what a lead answered.
 *
 * The eight standard answers on a lead are the questions every sale has. A
 * villa off-plan and a studio to let have more than that — "do you need a
 * maid's room", "is this for a family" — and those questions decide whether the
 * person is a buyer for *this* project. So a project can carry its own, and
 * each answer can carry its own consequence for the score.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_questions', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // A stable handle for the answer, so renaming the label later does
            // not orphan what people already said.
            $table->string('key', 60);
            $table->string('label');
            // select | boolean | number | text
            $table->string('type', 16);
            // For a select: the choices, as [{value, label}].
            $table->json('options')->nullable();
            // What an answer is worth: [{op, value, points, effect}].
            $table->json('rules')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'key']);
        });

        Schema::create('lead_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_question_id')->constrained()->cascadeOnDelete();
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['lead_id', 'project_question_id']);
        });

        Schema::table('leads', function (Blueprint $table) {
            // Priority is not a fourth temperature: it is a hot lead with one
            // more reason to be called first, and every number that counts hot
            // leads is still right.
            $table->boolean('is_priority')->default(false)->after('temperature');
            // The answers say this is not a buyer for this project.
            $table->boolean('wrong_fit')->default(false)->after('is_priority');
            $table->index(['tenant_id', 'is_priority']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'is_priority']);
            $table->dropColumn(['is_priority', 'wrong_fit']);
        });
        Schema::dropIfExists('lead_answers');
        Schema::dropIfExists('project_questions');
    }
};
