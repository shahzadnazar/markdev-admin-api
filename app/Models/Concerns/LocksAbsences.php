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
 * ## It took two commits to get this right
 *
 * e58ec3c put the trait on the daily register and audited the per-class sheet
 * as read-only on the strength of Api\V1\AttendanceController — missing
 * Admin\AttendanceController::save(), which upserted the other table. An
 * instructor could flip a class absence to present there, be told "Attendance
 * saved", and leave the two screens disagreeing. 946e8e2 closed that by
 * putting the trait on the second model too.
 *
 * The academy keeps attendance once now: the per-class table was folded into
 * the register and dropped, which is what makes this trait single-model again.
 * That is the real fix — a rule written twice is a rule that drifts, and a
 * fact recorded twice is two screens waiting to disagree. The trait stays a
 * trait so the rule keeps a name and a place of its own rather than
 * dissolving into the model it happens to be applied to.
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
     * Whether this row is a settled absence the current user cannot change.
     *
     * For the screens, so a correction control is not offered where it would
     * always be refused. It asks the same two questions the guard above does,
     * in the same place, because a view that decided this for itself would be
     * a third copy of the rule — and this codebase has already watched that
     * particular rule drift twice.
     *
     * Presentation only. Hiding a button is not a rule: the `updating` hook
     * and the controller check are what actually stop the write, and they run
     * whether or not anything was ever rendered.
     */
    public function isLockedAbsence(): bool
    {
        return $this->status === 'absent' && ! static::mayUndoAbsence();
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
