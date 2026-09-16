<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LearningActivity;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\PointEvent;
use App\Models\User;
use Illuminate\Support\Str;

class LessonProgressService
{
    public const POINTS_LESSON_COMPLETED = 10;

    public const POINTS_COURSE_COMPLETED = 50;

    /** Marks a lesson complete and returns the fresh course progress percent. */
    public function complete(User $user, Course $course, Lesson $lesson): float
    {
        $enrollment = $this->enrollmentOrFail($user, $course);

        $completion = LessonCompletion::firstOrCreate(
            ['user_id' => $user->id, 'lesson_id' => $lesson->id],
            ['course_id' => $course->id, 'completed_at' => now()],
        );

        if ($completion->wasRecentlyCreated) {
            LearningActivity::recordMinutes($user->id, (int) $lesson->duration_minutes);
            $this->awardPoints($user, self::POINTS_LESSON_COMPLETED, "Completed lesson: {$lesson->title}");
        }

        return $this->syncEnrollmentProgress($user, $course, $enrollment);
    }

    /** Unmarks a lesson and returns the fresh course progress percent. */
    public function uncomplete(User $user, Course $course, Lesson $lesson): float
    {
        $enrollment = $this->enrollmentOrFail($user, $course);

        LessonCompletion::where('user_id', $user->id)->where('lesson_id', $lesson->id)->delete();

        return $this->syncEnrollmentProgress($user, $course, $enrollment);
    }

    protected function enrollmentOrFail(User $user, Course $course): Enrollment
    {
        $enrollment = Enrollment::where('user_id', $user->id)->where('course_id', $course->id)->first();

        abort_unless($enrollment !== null, 403, 'You are not enrolled in this course.');

        return $enrollment;
    }

    /**
     * Recompute the cached figure and settle completion.
     *
     * enrollments.progress_percent is a CACHE of the live calculation and
     * nothing more. It exists so an admin list of fifty students is one query
     * rather than three hundred; no single-student surface reads it.
     *
     * Completion and the certificate are judged on COURSEWORK only — quiz,
     * assignment and premium content, renormalised among themselves. Attendance
     * is displayed in the breakdown and deliberately kept out of this: it is
     * the one component a student cannot go back and fix, so two missed days in
     * week one would put the certificate permanently out of reach however well
     * they worked afterwards. Absence already has its own consequence in the
     * fine. The two rules are the same rule so completed_at and the certificate
     * can never disagree.
     */
    protected function syncEnrollmentProgress(User $user, Course $course, Enrollment $enrollment): float
    {
        // ONE breakdown, not percent() plus courseworkPercent(): those run
        // every scorer twice, which showed up as 22 queries for a single lesson
        // completion. breakdown() scores each component once and combines the
        // same numbers two ways.
        $breakdown = app(CourseProgressCalculator::class)->breakdown($user, $course);

        $percent = $breakdown['total'];
        $coursework = $breakdown['coursework_total'];

        $enrollment->progress_percent = $percent;
        $enrollment->last_activity_at = now();

        if ($coursework >= 100) {
            $enrollment->completed_at ??= now();
            $this->issueCertificate($user, $course);
        } else {
            // Completion is recomputed, but an ISSUED CERTIFICATE IS NEVER
            // REVOKED. issueCertificate is a firstOrCreate and nothing here
            // deletes; a student who earned one keeps it even if the weights
            // later change what the percentage reads.
            $enrollment->completed_at = null;
        }

        $enrollment->save();

        return $percent;
    }

    protected function issueCertificate(User $user, Course $course): void
    {
        $certificate = Certificate::firstOrCreate(
            ['user_id' => $user->id, 'course_id' => $course->id],
            [
                'certificate_number' => $this->uniqueCertificateNumber(),
                'issued_at' => now(),
            ],
        );

        if ($certificate->wasRecentlyCreated) {
            $this->awardPoints($user, self::POINTS_COURSE_COMPLETED, "Completed course: {$course->title}");
        }
    }

    protected function uniqueCertificateNumber(): string
    {
        do {
            $number = 'MD-'.now()->year.'-'.Str::upper(Str::random(8));
        } while (Certificate::withTrashed()->where('certificate_number', $number)->exists());

        return $number;
    }

    protected function awardPoints(User $user, int $points, string $reason): void
    {
        PointEvent::create([
            'user_id' => $user->id,
            'points' => $points,
            'reason' => $reason,
            'created_at' => now(),
        ]);

        $user->increment('points', $points);
    }
}
