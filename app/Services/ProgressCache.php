<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;

/**
 * Keeps enrollments.progress_percent in step with the raw records.
 *
 * That column is a CACHE of CourseProgressCalculator and nothing more. It
 * exists for one reason: an admin list of fifty students would otherwise be
 * fifty live calculations — around 350 queries for one page. Every surface
 * about ONE student computes live instead and never reads it.
 *
 * ON THE EVENT, NOT ON A SCHEDULE. There is no nightly job. The moment a
 * component record changes the affected student's figure is recomputed, so a
 * list screen is never more than one save behind. The write paths are:
 *
 *   lesson completed / uncompleted  LessonProgressService::complete/uncomplete
 *                                   already call syncEnrollmentProgress
 *   quiz submitted                  QuizAttempt::saved
 *   assignment graded               AssignmentSubmission::saved
 *   attendance marked or corrected  DailyAttendance::saved / deleted
 *   weights changed                 SettingController::update
 *
 * Attendance is academy-wide, so one attendance row moves EVERY course the
 * student is enrolled in — hence forUser rather than for one course.
 */
class ProgressCache
{
    public function __construct(protected CourseProgressCalculator $calculator) {}

    /** Recompute one enrollment's cached figure. */
    public function refresh(User $user, Course $course): void
    {
        $enrollment = Enrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        if ($enrollment === null) {
            return;
        }

        $this->write($enrollment, $user, $course);
    }

    /** Recompute every course this student is enrolled in. */
    public function refreshForUser(User $user): void
    {
        Enrollment::where('user_id', $user->id)
            ->with('course')
            ->get()
            ->each(function (Enrollment $enrollment) use ($user) {
                if ($enrollment->course !== null) {
                    $this->write($enrollment, $user, $enrollment->course);
                }
            });
    }

    /** Recompute every enrollment on one course. */
    public function refreshForCourse(Course $course): void
    {
        Enrollment::where('course_id', $course->id)
            ->with('user')
            ->get()
            ->each(function (Enrollment $enrollment) use ($course) {
                if ($enrollment->user !== null) {
                    $this->write($enrollment, $enrollment->user, $course);
                }
            });
    }

    /**
     * Recompute the lot, in chunks.
     *
     * For a weights change, where every student's figure moves at once. Chunked
     * rather than loaded whole so an academy with thousands of enrolments does
     * not hold them all in memory.
     *
     * @return int how many enrolments were rewritten
     */
    public function refreshAll(): int
    {
        $done = 0;

        Enrollment::query()
            ->with(['user', 'course'])
            ->chunkById(200, function ($enrollments) use (&$done) {
                foreach ($enrollments as $enrollment) {
                    if ($enrollment->user === null || $enrollment->course === null) {
                        continue;
                    }

                    $this->write($enrollment, $enrollment->user, $enrollment->course);
                    $done++;
                }
            });

        return $done;
    }

    /**
     * Write the cached figure, and settle completion on the same rule the
     * service uses — coursework only, so completed_at can never disagree with
     * whether a certificate was earned.
     *
     * saveQuietly, because this is a cache write: it is not something the
     * student did, and firing Enrollment's own events for it would put audit
     * entries in the log for a number that only ever follows other numbers.
     * An ISSUED CERTIFICATE IS NEVER TOUCHED here — this never deletes one, and
     * never clears one that exists.
     */
    protected function write(Enrollment $enrollment, User $user, Course $course): void
    {
        // One pass over the raw records; both figures come out of it.
        $breakdown = $this->calculator->breakdown($user, $course);

        $percent = $breakdown['total'];
        $coursework = $breakdown['coursework_total'];

        $enrollment->progress_percent = $percent;
        $enrollment->completed_at = $coursework >= 100 ? ($enrollment->completed_at ?? now()) : null;

        $enrollment->saveQuietly();
    }
}
