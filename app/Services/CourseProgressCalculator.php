<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\DailyAttendance;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Quiz;
use App\Models\User;
use App\Support\ProgressWeights;

/**
 * A student's progress in a course, computed from the raw records.
 *
 * Progress used to be one stored number — completed lessons over total lessons,
 * written into enrollments.progress_percent. It is four components now, each
 * scored 0–100 from its own records and combined using the weights the admin
 * set. Nothing here reads a stored figure: the raw records are the truth and
 * the combined number is derived on every read.
 *
 * THE NO-DATA RULE. A component with no data IN THE COURSE AT ALL — a course
 * that sets no quizzes — is excluded and its weight shared out proportionally
 * across the components that remain. Scoring it 100 would hand every student
 * full marks for work nobody set.
 *
 * That is NOT the same as a student who has not done the work. An unattempted
 * quiz is a ZERO and the component stays in. The distinction is the whole
 * reason each scorer returns null rather than 0 for "nothing set": if the two
 * were confused, ignoring quizzes would RAISE a student's score, and skipping
 * every quiz in a course would be worth more than sitting them badly.
 */
class CourseProgressCalculator
{
    /**
     * The full breakdown for one student in one course.
     *
     * Roughly five queries: one per component plus the enrolment lookup. That
     * is fine for a page about one student and deliberately not fine for a list
     * of fifty, which is what enrollments.progress_percent is a cache for.
     *
     * @return array{
     *     components: array<int, array{key: string, label: string, weight: int,
     *         effective_weight: float, score: float|null, contribution: float, excluded: bool}>,
     *     total: float, coursework_total: float
     * }
     */
    public function breakdown(User $user, Course $course): array
    {
        $weights = ProgressWeights::enabled();
        $scores = $this->scores($user, $course, array_keys($weights));

        return [
            'components' => $this->components($weights, $scores),
            'total' => $this->combine($weights, $scores),
            // What a certificate is judged on: coursework only, renormalised
            // among themselves. Attendance is displayed but never decides one.
            'coursework_total' => $this->combine(
                array_intersect_key($weights, array_flip(ProgressWeights::COURSEWORK)),
                $scores,
            ),
        ];
    }

    /** Just the combined figure — what the cache stores and lists show. */
    public function percent(User $user, Course $course): float
    {
        $weights = ProgressWeights::enabled();

        return $this->combine($weights, $this->scores($user, $course, array_keys($weights)));
    }

    /** Just the coursework figure — what decides a certificate. */
    public function courseworkPercent(User $user, Course $course): float
    {
        $weights = ProgressWeights::enabledCoursework();

        return $this->combine($weights, $this->scores($user, $course, array_keys($weights)));
    }

    /**
     * Each component's score, or null when the course has no such data.
     *
     * @param  array<int, string>  $components
     * @return array<string, float|null>
     */
    public function scores(User $user, Course $course, array $components): array
    {
        $out = [];

        foreach ($components as $component) {
            $out[$component] = match ($component) {
                'premium' => $this->premiumScore($user, $course),
                'quiz' => $this->quizScore($user, $course),
                'assignment' => $this->assignmentScore($user, $course),
                'attendance' => $this->attendanceScore($user, $course),
                default => null,
            };
        }

        return $out;
    }

    /**
     * Weighted mean over the components that have data.
     *
     * The excluded ones drop out of BOTH the numerator and the denominator,
     * which is the redistribution: three components of 20 with a fourth
     * excluded become three of 20/60 each, not three of 20/80 with 20 missing.
     *
     * @param  array<string, int>  $weights
     * @param  array<string, float|null>  $scores
     */
    protected function combine(array $weights, array $scores): float
    {
        $totalWeight = 0;
        $earned = 0.0;

        foreach ($weights as $component => $weight) {
            $score = $scores[$component] ?? null;

            if ($score === null) {
                continue;
            }

            $totalWeight += $weight;
            $earned += $score * $weight;
        }

        // Every component excluded — a course with no lessons, no quizzes and
        // no assignments, for a student with no attendance. Zero, not a
        // division by zero, and not 100.
        return $totalWeight > 0 ? round($earned / $totalWeight, 2) : 0.0;
    }

    /**
     * The per-component rows the portal renders.
     *
     * effective_weight is what the component is ACTUALLY worth once exclusions
     * have been shared out, so the displayed contributions add up to the total
     * rather than to something smaller.
     */
    protected function components(array $weights, array $scores): array
    {
        $liveWeight = 0;
        foreach ($weights as $component => $weight) {
            if (($scores[$component] ?? null) !== null) {
                $liveWeight += $weight;
            }
        }

        $rows = [];

        foreach ($weights as $component => $weight) {
            $score = $scores[$component] ?? null;
            $excluded = $score === null;
            $effective = ($excluded || $liveWeight === 0) ? 0.0 : round($weight / $liveWeight * 100, 2);

            $rows[] = [
                'key' => $component,
                'label' => ProgressWeights::LABELS[$component] ?? $component,
                'weight' => $weight,
                'effective_weight' => $effective,
                'score' => $score === null ? null : round($score, 1),
                'contribution' => $excluded ? 0.0 : round($score * $effective / 100, 2),
                'excluded' => $excluded,
            ];
        }

        return $rows;
    }

    /* ------------------------------- scorers ------------------------------- */

    /** Completed lessons over total lessons — the old formula, now one of four. */
    protected function premiumScore(User $user, Course $course): ?float
    {
        $total = Lesson::where('course_id', $course->id)->count();

        // A course with no lessons has no premium content to measure.
        if ($total === 0) {
            return null;
        }

        $done = LessonCompletion::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->count();

        return min(100.0, $done / $total * 100);
    }

    /**
     * Best attempt per quiz, averaged across every published quiz.
     *
     * Best attempt rather than latest or first: a quiz that allows three
     * attempts is saying the best of them is the student's answer, and marking
     * the last one would punish someone for trying again after they had
     * already done well.
     *
     * An UNATTEMPTED quiz scores 0 and stays in the average — the average is
     * over the quizzes the course SET, not the ones the student chose to sit.
     * Only a course with no published quizzes at all returns null.
     */
    protected function quizScore(User $user, Course $course): ?float
    {
        $rows = Quiz::query()
            ->where('quizzes.course_id', $course->id)
            ->where('quizzes.is_published', true)
            ->leftJoin('quiz_attempts', function ($join) use ($user) {
                $join->on('quiz_attempts.quiz_id', '=', 'quizzes.id')
                    ->where('quiz_attempts.user_id', '=', $user->id)
                    ->whereNotNull('quiz_attempts.submitted_at');
            })
            ->groupBy('quizzes.id')
            // max_score can be 0 on a quiz with no questions; guard the divide
            // in SQL so such a quiz scores 0 rather than erroring.
            ->selectRaw('quizzes.id, max(case when coalesce(quiz_attempts.max_score, 0) > 0'
                .' then quiz_attempts.score * 100.0 / quiz_attempts.max_score else 0 end) as best')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        return min(100.0, $rows->avg(fn ($row) => (float) ($row->best ?? 0)));
    }

    /**
     * Graded score over max, averaged across every assignment in the course.
     *
     * A submission that has not been graded yet scores 0, the same as one never
     * made. That is the honest reading while it is true: an ungraded submission
     * has earned nothing so far, and the figure moves the moment the instructor
     * grades it — which is one of the events that refreshes the cache.
     *
     * Only a course that sets no assignments returns null.
     */
    protected function assignmentScore(User $user, Course $course): ?float
    {
        $rows = Assignment::query()
            ->where('assignments.course_id', $course->id)
            ->leftJoin('assignment_submissions', function ($join) use ($user) {
                $join->on('assignment_submissions.assignment_id', '=', 'assignments.id')
                    ->where('assignment_submissions.user_id', '=', $user->id)
                    ->whereNull('assignment_submissions.deleted_at')
                    ->whereNotNull('assignment_submissions.graded_at');
            })
            ->groupBy('assignments.id')
            ->selectRaw('assignments.id, max(case when coalesce(assignments.max_score, 0) > 0'
                .' then coalesce(assignment_submissions.score, 0) * 100.0 / assignments.max_score else 0 end) as best')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        return min(100.0, $rows->avg(fn ($row) => (float) ($row->best ?? 0)));
    }

    /**
     * The existing weighted attendance percentage — not a second one.
     *
     * DailyAttendance::weightedPercent is what the register, the rules page and
     * the absence fine already use, so present/late/leave/excused/absent are
     * worth here exactly what an academy configured them to be worth there.
     * Holidays and non-working days never reach it: HOLIDAY and PENDING are
     * absent from WEIGHTS, and `counted` filters to the marked statuses.
     *
     * Attendance is a fact about the STUDENT at the academy, not about one
     * course: the calendar, the leave allowance and the absence fine are all
     * academy-wide, and daily_attendance_records.course_id is nullable and only
     * partly populated. It is scoped to the enrolment date onward, so a student
     * who joined last week is not judged on a month they were not here.
     *
     * A student with no marked days yet has no attendance data — null, not 0,
     * or joining mid-term would read as a month of absences.
     */
    protected function attendanceScore(User $user, Course $course): ?float
    {
        $from = Enrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->value('enrolled_at');

        $counts = DailyAttendance::query()
            ->where('user_id', $user->id)
            ->counted()
            // whereDate, never an equality or a raw compare on a date-cast
            // column: the same trap has been hit eight times in this codebase.
            ->when($from, fn ($query) => $query->whereDate('date', '>=', $from->toDateString()))
            ->groupBy('status')
            ->selectRaw('status, count(*) as days')
            ->pluck('days', 'status')
            ->all();

        if (array_sum($counts) === 0) {
            return null;
        }

        return DailyAttendance::weightedPercent($counts);
    }
}
