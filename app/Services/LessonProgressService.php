<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LearningActivity;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\LessonResource;
use App\Models\MaterialRead;
use App\Models\PointEvent;
use App\Models\User;
use Illuminate\Support\Str;

class LessonProgressService
{
    public const POINTS_LESSON_COMPLETED = 10;

    public const POINTS_COURSE_COMPLETED = 50;

    public const MINUTES_MATERIAL_READ = 5;

    /** Marks a lesson complete and returns the fresh course progress percent. */
    public function complete(User $user, Course $course, Lesson $lesson): float
    {
        $enrollment = $this->enrollmentOrFail($user, $course);

        $completion = LessonCompletion::firstOrCreate(
            ['user_id' => $user->id, 'lesson_id' => $lesson->id],
            ['course_id' => $course->id, 'completed_at' => now()],
        );

        if ($completion->wasRecentlyCreated) {
            $this->recordLearningMinutes($user, (int) $lesson->duration_minutes);
            $this->awardPoints($user, self::POINTS_LESSON_COMPLETED, "Completed lesson: {$lesson->title}");
        }

        return $this->syncEnrollmentProgress($user, $course, $enrollment);
    }

    /**
     * Credits a study-material read: activity minutes always, and for
     * read-style lessons (resource/article) the lesson auto-completes once
     * every attached file has been read — feeding course progress percent.
     */
    public function recordMaterialRead(User $user, LessonResource $resource): void
    {
        $this->recordLearningMinutes($user, self::MINUTES_MATERIAL_READ);

        $lesson = $resource->lesson()->with('course')->first();

        if ($lesson === null || $lesson->course === null
            || ! in_array($lesson->type, ['resource', 'article'], true)) {
            return;
        }

        $unread = $lesson->resources()
            ->whereNotIn('id', MaterialRead::where('user_id', $user->id)->select('lesson_resource_id'))
            ->exists();

        if (! $unread) {
            $this->complete($user, $lesson->course, $lesson);
        }
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

    protected function syncEnrollmentProgress(User $user, Course $course, Enrollment $enrollment): float
    {
        $totalLessons = Lesson::where('course_id', $course->id)->count();
        $completedLessons = LessonCompletion::where('user_id', $user->id)->where('course_id', $course->id)->count();

        $percent = $totalLessons > 0 ? round($completedLessons / $totalLessons * 100, 2) : 0.0;

        $enrollment->progress_percent = $percent;
        $enrollment->last_activity_at = now();

        if ($percent >= 100) {
            $enrollment->completed_at ??= now();
            $this->issueCertificate($user, $course);
        } else {
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

    protected function recordLearningMinutes(User $user, int $minutes): void
    {
        $activity = LearningActivity::where('user_id', $user->id)
            ->whereDate('date', now()->toDateString())
            ->first();

        $activity ??= new LearningActivity([
            'user_id' => $user->id,
            'date' => now()->toDateString(),
        ]);

        $activity->minutes = (int) $activity->minutes + $minutes;
        $activity->save();
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
