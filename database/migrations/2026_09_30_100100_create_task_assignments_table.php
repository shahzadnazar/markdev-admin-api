<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A STINT: one person, one task, one period of responsibility.
 *
 * The delivery score is computed over these rows, never over tasks. A task can
 * change hands, be reopened, and be worked by three people in turn; each of
 * those is its own promise with its own allowance, and scoring the task would
 * have to pick one of them to blame.
 *
 * A REASSIGNMENT closes the open stint as `handed_over` and opens a new one
 * whose days_allowed the lead types. The remaining days are never carried over
 * silently: somebody has to be accountable for the new promise, and a stint
 * opened with whatever was left would doom whoever picked it up for a delay
 * that was not theirs.
 *
 * A REOPEN closes nothing retroactively. The first stint keeps its real
 * ended_on and its real outcome, and a new stint opens beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // What THIS person was given, by their lead, for THIS stint. The
            // only number their score is ever measured against.
            $table->unsignedInteger('days_allowed');

            $table->date('started_on');
            $table->date('ended_on')->nullable();

            // One of TaskAssignment::OUTCOMES. A fixed set in PHP, never a
            // database enum — the same discipline as TaskStatus::BEHAVIOURS,
            // because the code is what branches on it.
            $table->string('outcome', 20)->nullable();

            // Who opened it. A stint is a promise somebody made on somebody
            // else's behalf, so the trail has to name them.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'outcome']);
            $table->index(['task_id', 'ended_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_assignments');
    }
};
