<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The checkpoints a project is delivered in.
 *
 * A milestone belongs to a PROJECT and to nothing else. It carries no team of
 * its own: the project already says which team is doing the work, and a second
 * answer to that question is a second thing to keep in step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();

            // Cascade: a force-deleted project's milestones mean nothing on
            // their own. A soft delete leaves them, along with the history.
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('name', 160);

            // `completed_on`, not `completed_at`: this is the DAY a milestone
            // was signed off, and it is cast `date:Y-m-d`. The name also keeps
            // it clear of the datetime `completed_at` the academy models carry,
            // which DateColumnGuardTest would otherwise start policing as a
            // date column across code that has nothing to do with this.
            $table->date('due_date')->nullable();
            $table->date('completed_on')->nullable();

            $table->unsignedInteger('sort_order')->default(0);

            /*
             * Whether the client is shown this milestone.
             *
             * NOTHING RENDERS THIS IN PHASE 2 — the client portal is phase 6.
             * It is written now, defaulting to FALSE, because adding a
             * visibility flag to rows that already exist means deciding what
             * those rows were supposed to mean, and the safe answer for a
             * milestone nobody marked is "not shared".
             */
            $table->boolean('is_client_visible')->default(false);

            $table->timestamps();

            $table->index(['project_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_milestones');
    }
};
