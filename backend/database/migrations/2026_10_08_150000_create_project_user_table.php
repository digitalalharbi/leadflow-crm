<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which reps work which project.
 *
 * A developer selling three compounds does not have one sales team, it has
 * three — the rep who knows النرجس can answer what the service charge covers
 * and which units face the park, and the rep who does not has to go and ask.
 * Assignment could not express that: every lead went to whoever was least busy
 * across the whole company, however little they knew about the project it came
 * from.
 *
 * Empty means "anybody", which is what every workspace starts as and what most
 * of them will stay as. Nothing changes until somebody says a project has a
 * team.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_user');
    }
};
