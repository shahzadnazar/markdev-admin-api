<?php

namespace App\Models\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

/**
 * An absence, once recorded, can only be undone by someone allowed to.
 *
 * 459f3cc put that rule in the two controller methods that wrote the daily
 * register at the time. Two call sites became four, and the one added last —
 * releasing a closed absence when a leave is approved late — never called it.
 * An instructor, who may now review leave, could undo a billable absence
 * without holding `attendance.correct-absent`.
 *
 * So the rule lives on the model instead. Every write goes through Eloquent's
 * `updating` event, which is the one place a fifth caller cannot forget: a
 * path that fails to ask permission does not silently succeed, it throws.
 *
 * Callers that want to behave gracefully — skipping the locked rows and
 * saying how many they skipped, rather than failing the whole request — ask
 * `mayUndoAbsence()` first. This is the backstop under them, not a
 * replacement for it.
 *
 * Deliberately scoped to a *user* acting. With nobody authenticated the write
 * is the system's own — the nightly close, the fine run, a console command —
 * and those are not a person circumventing a rule. The close never revisits a
 * settled day anyway; it only fills blanks.
 *
 * ## Both attendance tables
 *
 * This academy keeps attendance twice: `daily_attendance_records` is the
 * day-per-student register that fines and percentages read, and
 * `attendance_records` is the per-class-session sheet an instructor marks
 * from Learning → Attendance. They share no key and neither writes the other.
 *
 * e58ec3c applied this trait to the register only, and audited the class
 * sheet as read-only on the strength of Api\V1\AttendanceController — missing
 * Admin\AttendanceController::save(), which upserts `attendance_records`. So
 * an instructor could flip a class absence to present there, be told
 * "Attendance saved", and leave the two screens disagreeing. The trait is on
 * both models now.
 *
 * Reusing it needed nothing generalised: both tables spell the status
 * `absent` (`daily_attendance_records` in STATUSES, `attendance_records` in
 * its enum of present/absent/late/excused), and both write through model
 * instances, so `updating` fires on both. `AttendanceRecord::updateOrCreate`
 * is `firstOrNew()->fill()->save()` — an instance save, events and all —
 * which is exactly why it is safe and exactly why the query-builder note
 * below is the thing to watch.
 *
 * One thing this does NOT cover, and it is worth knowing: a mass update
 * through the query builder — `DailyAttendance::where(...)->update([...])` —
 * fires no model events and so walks straight past this. There is exactly one
 * such site today, in CloseAttendanceDay, and it only ever writes rows it has
 * already filtered to `pending`. AbsenceLockTest keeps a list of them, for
 * both models, and fails when a new one appears, so the gap stays known
 * rather than becoming the next forgotten call site.
 */
trait LocksAbsences
{
    public static function bootLocksAbsences(): void
    {
        static::updating(function (self $record): void {
            if (! $record->isUndoingAnAbsence()) {
                return;
            }

            if (! static::mayUndoAbsence()) {
                throw new AuthorizationException('Absent is final. Ask an admin to correct it.');
            }
        });
    }

    /** Whether this pending change turns a recorded absence into something else. */
    public function isUndoingAnAbsence(): bool
    {
        return $this->getOriginal('status') === 'absent'
            && $this->isDirty('status')
            && $this->status !== 'absent';
    }

    /**
     * Whether the person acting may undo an absence.
     *
     * True when nobody is authenticated: that is the scheduler or a console
     * command, not a user working around the lock.
     */
    public static function mayUndoAbsence(): bool
    {
        $user = Auth::user();

        return $user === null || $user->can('attendance.correct-absent');
    }
}
