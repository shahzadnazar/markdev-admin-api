<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How long a task, and a project, sat on each status.
 *
 * WHY THESE EXIST. A stint's days taken exclude the days its task was BLOCKED
 * and the days its project was PAUSED. "Was blocked" is a question about the
 * past, and a status_id column only answers "is blocked" — so the transitions
 * have to be recorded as they happen or the exclusion is unknowable after the
 * fact. Both tables are append-then-close: the open period has a null
 * ended_on, and the next change stamps it.
 *
 * The exclusions are decided on the status BEHAVIOUR, never on its label. An
 * admin renaming "Blocked" to "Waiting on client" must not move a single
 * figure, which is why these store the status id and the counter joins to the
 * behaviour rather than storing a word.
 *
 * `reason` is on the task table only. Parking a task in Blocked stops that
 * stint's clock, so it takes a written reason — the same shape as declining a
 * leave application. A member may do it; they are the first to know they are
 * stuck. Parking is visible, not forbidden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_status_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_status_id')->constrained()->restrictOnDelete();
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['task_id', 'ended_on']);
        });

        Schema::create('project_status_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_status_id')->constrained()->restrictOnDelete();
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'ended_on']);
        });

        /*
         * Open a period for every project that already exists.
         *
         * Without this a project created in phase 2 has no history at all, and
         * the counter would read "never paused" for one that has been on hold
         * since the day it was set up. Dated from the project's start date
         * where it has one, and from its creation otherwise — the earliest
         * moment we can honestly claim it was on this status.
         */
        foreach (DB::table('projects')->select('id', 'project_status_id', 'start_date', 'created_at')->get() as $project) {
            DB::table('project_status_periods')->insert([
                'project_id' => $project->id,
                'project_status_id' => $project->project_status_id,
                'started_on' => substr((string) ($project->start_date ?: $project->created_at), 0, 10),
                'ended_on' => null,
                'changed_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_status_periods');
        Schema::dropIfExists('project_status_periods');
    }
};
