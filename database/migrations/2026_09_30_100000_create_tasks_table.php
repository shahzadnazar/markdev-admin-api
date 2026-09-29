<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A unit of work a team does.
 *
 * TASKS CARRY THEIR OWN team_id rather than reaching through the project. Two
 * reasons, and both matter: the board is then one query instead of a join per
 * column, and INTERNAL WORK — which has no client and therefore no project —
 * still has a home. That is the only reason project_id is nullable.
 *
 * ONE LEVEL OF SPLITTING. A task may be split into parts; a part may not be
 * split again. Enforced on save, not only on the form: a three-level tree has
 * no answer to "whose days is this against", and the moment it exists somebody
 * has to invent one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();

            // Required. Visibility is decided on this column and nothing else,
            // so a task is never invisible for want of a project.
            $table->foreignId('team_id')->constrained()->restrictOnDelete();

            // Nullable ONLY because internal work has no client and so no
            // project. When it is set, team_id must equal the project's
            // team_id — enforced on the model, not just in the form.
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();

            // One level only. A part points at its parent; a part may not be a
            // parent. Cascade because a part with no parent is meaningless.
            $table->foreignId('parent_id')->nullable()->constrained('tasks')->cascadeOnDelete();

            $table->foreignId('task_status_id')->constrained()->restrictOnDelete();

            $table->string('title', 200);
            $table->text('description')->nullable();

            /*
             * What the ADMIN promised for this task.
             *
             * The parent's own allowance is what the project is judged
             * against. It is NOT what a person is judged against — that is
             * their stint's allowance, on task_assignments. Two clocks, kept
             * apart on purpose; see TaskAssignment.
             */
            $table->unsignedInteger('days_allowed')->default(0);

            // Cast `date:Y-m-d` and only ever queried through ScopesToDay.
            $table->date('started_on')->nullable();
            $table->date('due_date')->nullable();

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'task_status_id']);
            $table->index('parent_id');
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
