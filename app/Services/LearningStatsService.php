<?php

namespace App\Services;

use App\Models\LearningActivity;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\User;
use App\Models\Note;
use App\Models\NoteRead;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LearningStatsService
{
    /**
     * Daily learning minutes for the trailing $days days (zero-filled,
     * oldest first, ending today).
     *
     * @return array<int, array{date: string, minutes: int}>
     */
    public function activitySeries(User $user, int $days): array
    {
        $start = now()->subDays($days - 1)->startOfDay();

        $minutesByDate = LearningActivity::where('user_id', $user->id)
            ->whereDate('date', '>=', $start->toDateString())
            ->get()
            ->keyBy(fn(LearningActivity $activity) => $activity->date->toDateString());

        $series = [];
        for ($day = $start->copy(); $day->lte(now()); $day->addDay()) {
            $key = $day->toDateString();
            $series[] = [
                'date' => $key,
                'minutes' => (int) ($minutesByDate->get($key)?->minutes ?? 0),
            ];
        }

        return $series;
    }

    /** Consecutive days with learning minutes > 0, ending today or yesterday. */
    public function currentStreak(User $user): int
    {
        return $this->currentStreakFromDates($this->activeDates($user));
    }

    /** @param  Collection<string, mixed>  $dates  set keyed by Y-m-d */
    public function currentStreakFromDates(Collection $dates): int
    {
        $day = now()->startOfDay();

        if (! $dates->has($day->toDateString())) {
            $day = $day->subDay();
        }

        $streak = 0;
        while ($dates->has($day->toDateString())) {
            $streak++;
            $day = $day->subDay();
        }

        return $streak;
    }

    public function longestStreak(User $user): int
    {
        $dates = $this->activeDates($user)->keys()->sort()->values();

        $longest = 0;
        $run = 0;
        $previous = null;

        foreach ($dates as $date) {
            $run = ($previous !== null && \Carbon\Carbon::parse($previous)->addDay()->toDateString() === $date)
                ? $run + 1
                : 1;
            $longest = max($longest, $run);
            $previous = $date;
        }

        return $longest;
    }

    /** @return Collection<string, bool> set of active Y-m-d dates */
    public function activeDates(User $user): Collection
    {
        return LearningActivity::where('user_id', $user->id)
            ->where('minutes', '>', 0)
            ->pluck('date')
            ->mapWithKeys(fn($date) => [$date->toDateString() => true]);
    }

    /**
     * Attaches `completed_lessons`, `total_lessons` and `time_spent_minutes`
     * attributes to each enrollment (course relation expected to be loaded)
     * using three batched queries.
     *
     * @param  Collection<int, \App\Models\Enrollment>  $enrollments
     * @return Collection<int, \App\Models\Enrollment>
     */
    public function hydrateCourseProgress(User $user, Collection $enrollments): Collection
    {
        $courseIds = $enrollments->pluck('course_id')->all();

        $totals = Lesson::whereIn('course_id', $courseIds)
            ->select('course_id', DB::raw('count(*) as total'))
            ->groupBy('course_id')
            ->pluck('total', 'course_id');

        $completed = LessonCompletion::where('user_id', $user->id)
            ->whereIn('course_id', $courseIds)
            ->select('course_id', DB::raw('count(*) as total'))
            ->groupBy('course_id')
            ->pluck('total', 'course_id');

        // Time spent per course, apportioned as the sum of completed lesson durations.
        $timeSpent = LessonCompletion::where('lesson_completions.user_id', $user->id)
            ->whereIn('lesson_completions.course_id', $courseIds)
            ->join('lessons', 'lessons.id', '=', 'lesson_completions.lesson_id')
            ->select('lesson_completions.course_id', DB::raw('sum(lessons.duration_minutes) as total'))
            ->groupBy('lesson_completions.course_id')
            ->pluck('total', 'course_id');

        return $enrollments->each(function ($enrollment) use ($totals, $completed, $timeSpent) {
            $enrollment->setAttribute('total_lessons', (int) ($totals[$enrollment->course_id] ?? 0));
            $enrollment->setAttribute('completed_lessons', (int) ($completed[$enrollment->course_id] ?? 0));
            $enrollment->setAttribute('time_spent_minutes', (int) ($timeSpent[$enrollment->course_id] ?? 0));
        });
    }
    /**
     * Monthly learning progress for the trailing number of months.
     *
     * Tracks five completely independent activities:
     *
     * - completed lessons
     * - completed quizzes
     * - submitted assignments
     * - attendance
     * - notes read
     *
     * All values are percentages from 0–100.
     *
     * @return array<int, array{
     *     month: string,
     *     lessons: float|int,
     *     quizzes: float|int,
     *     assignments: float|int,
     *     attendance: float|int,
     *     notes: float|int
     * }>
     */
    public function progressSeries(User $user, int $months = 12): array
    {
        $start = now()
            ->subMonths($months - 1)
            ->startOfMonth();

        /*
     * -------------------------------------------------------------
     * Enrolled courses
     * -------------------------------------------------------------
     */
        $courseIds = $user->enrollments()
            ->pluck('course_id');

        /*
     * -------------------------------------------------------------
     * LESSONS
     * -------------------------------------------------------------
     */
        $lessonCompletions = LessonCompletion::where('user_id', $user->id)
            ->whereDate('completed_at', '>=', $start->toDateString())
            ->get()
            ->groupBy(
                fn($completion) =>
                $completion->completed_at
                    ->copy()
                    ->startOfMonth()
                    ->toDateString()
            )
            ->map(fn($items) => $items->count());

        $totalLessons = Lesson::whereIn('course_id', $courseIds)->count();

        /*
     * -------------------------------------------------------------
     * QUIZZES
     * -------------------------------------------------------------
    
 *
 * Count UNIQUE quizzes completed in each month.
 *
 * Multiple attempts on the same quiz count as ONE completed quiz.
 */
        $quizCompletions = \App\Models\QuizAttempt::where('user_id', $user->id)
            ->whereNotNull('submitted_at')
            ->whereDate('submitted_at', '>=', $start->toDateString())
            ->get()
            ->groupBy(function ($attempt) {
                return $attempt->submitted_at
                    ->copy()
                    ->startOfMonth()
                    ->toDateString();
            })
            ->map(function ($items) {
                return $items
                    ->pluck('quiz_id')
                    ->unique()
                    ->count();
            });

        $totalQuizzes = \App\Models\Quiz::whereIn('course_id', $courseIds)->count();

        /*
     * -------------------------------------------------------------
     * ASSIGNMENTS
     * -------------------------------------------------------------
     */
        $assignmentSubmissions = \App\Models\AssignmentSubmission::where('user_id', $user->id)
            ->whereNotNull('submitted_at')
            ->whereDate('submitted_at', '>=', $start->toDateString())
            ->get()
            ->groupBy(
                fn($submission) =>
                $submission->submitted_at
                    ->copy()
                    ->startOfMonth()
                    ->toDateString()
            )
            ->map(fn($items) => $items->count());

        $totalAssignments = \App\Models\Assignment::whereHas(
            'lesson',
            fn($query) => $query->whereIn('course_id', $courseIds)
        )->count();

        /*
     * -------------------------------------------------------------
     * ATTENDANCE
     * -------------------------------------------------------------
     */
        $attendanceRecords = \App\Models\AttendanceRecord::where('user_id', $user->id)
            ->whereDate('date', '>=', $start->toDateString())
            ->get()
            ->groupBy(
                fn($record) =>
                $record->date
                    ->copy()
                    ->startOfMonth()
                    ->toDateString()
            );

        /*
     * -------------------------------------------------------------
     * NOTES
     * -------------------------------------------------------------
     *
     * IMPORTANT:
     * Notes are completely separate from LessonCompletion
     * and completely separate from MaterialRead.
     *
     * A note counts only when the student opens/reads that Note.
     */
        $noteReads = NoteRead::where('user_id', $user->id)
            ->whereDate('read_at', '>=', $start->toDateString())
            ->get()
            ->groupBy(
                fn($read) =>
                $read->read_at
                    ->copy()
                    ->startOfMonth()
                    ->toDateString()
            )
            ->map(fn($items) => $items->count());

        /*
     * Total notes available to this student.
     */
        $totalNotes = Note::whereIn('course_id', $courseIds)->count();

        /*
     * -------------------------------------------------------------
     * BUILD MONTHLY SERIES
     * -------------------------------------------------------------
     */
        $series = [];

        $month = $start->copy()->startOfMonth();
        $end = now()->startOfMonth();

        while ($month->lte($end)) {
            $key = $month->toDateString();

            /*
         * Lessons
         */
            $lessonPercent = $totalLessons > 0
                ? round(
                    (($lessonCompletions[$key] ?? 0) / $totalLessons) * 100,
                    1
                )
                : 0;

            /*
       
 * Quizzes
 */
            $quizPercent = $totalQuizzes > 0
                ? round(
                    (($quizCompletions[$key] ?? 0) / $totalQuizzes) * 100,
                    1
                )
                : 0;

            /*
         * Assignments
         */
            $assignmentPercent = $totalAssignments > 0
                ? round(
                    (($assignmentSubmissions[$key] ?? 0) / $totalAssignments) * 100,
                    1
                )
                : 0;

            /*
         * Attendance
         */
            $monthAttendance = $attendanceRecords[$key] ?? collect();

            $totalAttendance = $monthAttendance->count();

            $presentAttendance = $monthAttendance
                ->where('status', 'present')
                ->count();

            $attendancePercent = $totalAttendance > 0
                ? round(
                    ($presentAttendance / $totalAttendance) * 100,
                    1
                )
                : 0;

            /*
         * Notes
         *
         * NOTE READS ONLY.
         * Does NOT affect lessons.
         * Does NOT affect quizzes.
         * Does NOT affect assignments.
         * Does NOT affect attendance.
         */
            $notesPercent = $totalNotes > 0
                ? round(
                    (($noteReads[$key] ?? 0) / $totalNotes) * 100,
                    1
                )
                : 0;

            $series[] = [
                'month' => $key,
                'lessons' => $lessonPercent,
                'quizzes' => $quizPercent,
                'assignments' => $assignmentPercent,
                'attendance' => $attendancePercent,
                'notes' => $notesPercent,
            ];

            $month->addMonth();
        }

        return $series;
    }
}
