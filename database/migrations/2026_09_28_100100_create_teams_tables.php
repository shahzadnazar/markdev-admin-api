<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teams, and who is in them.
 *
 * A team is a group of MarkDev staff that client work is assigned to. It is
 * not a course, a category or a class: nothing in the academy reads these
 * tables, and nothing here reads the academy's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);

            /*
             * The normalised name the unique index sits on, exactly as
             * attendance slots do it.
             *
             * Nullable, and cleared when the row is soft-deleted, because an
             * index on `name` alone would reserve a deleted team's name for
             * ever: dismantling "Web" and starting a new "Web" next quarter is
             * an ordinary thing to want.
             */
            $table->string('name_key', 80)->nullable();

            // Exactly one lead per team, and the form additionally requires
            // that they are a member of it. Nullable because the FK nulls
            // rather than cascades: erasing an account must not take a team
            // and its whole history with it. The form never accepts a blank.
            $table->foreignId('team_lead_id')->nullable()->constrained('users')->nullOnDelete();

            // Deactivating a team stops new work being pointed at it. It keeps
            // its members, its projects and its history — which is the whole
            // reason this is a flag and not a delete.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique('name_key');
            $table->index('is_active');
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // One row per person per team. A member MAY belong to several
            // teams — a designer working across two products is normal — so
            // the pair is unique rather than either column on its own.
            $table->unique(['team_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
    }
};
