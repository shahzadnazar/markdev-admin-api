<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The team portal's own register.
 *
 * A SEPARATE TABLE, and this is the load-bearing decision of the phase.
 * daily_attendance_records is the most-patched thing in this codebase: the
 * absent lock leaked out of it twice, the date-cast trap lives there, and two
 * attendance systems were once merged into it. A second population in it means
 * every academy query that forgets to exclude team members shows staff in a
 * student register or folds their days into a student's fine — and there are a
 * great many of those queries.
 *
 * REUSE IS BY COMPOSITION INSTEAD. The model uses the same LocksAbsences and
 * ScopesToDay traits, and the same AttendanceMath, rather than copies of them.
 * The shape below deliberately matches the academy register's where it makes
 * sense so the shared code has nothing to special-case.
 *
 * NO SLOTS. The team portal has no slot concept: lateness is office start plus
 * a grace, both settings. There is no per-person start and no second slot
 * table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Cast `date:Y-m-d` and only ever queried through ScopesToDay.
            $table->date('date');

            // present | late | leave | absent, plus the two the model keeps out
            // of STATUSES: `pending` for a day nobody has settled and `holiday`
            // for a dated closure. `excused` was retired in a02a5a2 and is not
            // coming back.
            $table->string('status', 20)->default('pending');

            $table->text('remarks')->nullable();
            $table->time('arrived_at')->nullable();

            // How the row got here: a person marking, or the nightly close.
            $table->string('source', 20)->default('manual');

            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('marked_at')->nullable();

            // The correction trail the absent lock already expects. An absence
            // is undone only by somebody holding attendance.correct-absent, in
            // writing — the trait enforces it, on this model as on the other.
            $table->foreignId('last_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('last_update_reason')->nullable();
            $table->timestamp('last_updated_at')->nullable();

            $table->timestamps();

            // One row per person per day, which is what makes the close safe to
            // re-run and a double-mark impossible.
            $table->unique(['user_id', 'date']);
            $table->index(['date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_attendance_records');
    }
};
