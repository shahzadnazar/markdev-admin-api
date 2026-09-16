<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\DailyAttendance;
use App\Models\LessonCompletion;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Setting;
use App\Models\User;
use App\Services\CourseProgressCalculator;
use App\Services\LessonProgressService;
use App\Support\ProgressWeights;
use Illuminate\Support\Facades\DB;

/**
 * Four components, combined on read from the raw records.
 *
 * The arithmetic is asserted against numbers worked out by hand in each test,
 * not against whatever the code happens to produce — a progress figure that
 * only matches itself is not a test.
 */
class CourseProgressCalculatorTest extends ApiTestCase
{
    protected function calculator(): CourseProgressCalculator
    {
        return app(CourseProgressCalculator::class);
    }

    protected function weights(array $percents, array $disabled = []): void
    {
        foreach ($percents as $component => $percent) {
            Setting::updateOrCreate(['key' => 'progress_weight_'.$component], ['value' => $percent, 'group' => 'general']);
        }
        foreach (array_keys(ProgressWeights::DEFAULTS) as $component) {
            Setting::updateOrCreate(
                ['key' => 'progress_enabled_'.$component],
                ['value' => in_array($component, $disabled, true) ? 0 : 1, 'group' => 'general'],
            );
        }
        Setting::forgetCached();
    }

    /** n published quizzes on the course, each out of 10. */
    protected function quiz(Course $course, string $title = 'Quiz'): Quiz
    {
        return Quiz::create([
            'course_id' => $course->id, 'title' => $title, 'description' => 'x',
            'attempts_allowed' => 3, 'passing_score' => 50, 'is_published' => true,
        ]);
    }

    protected function attempt(Quiz $quiz, User $user, int $score, int $max = 10): QuizAttempt
    {
        return QuizAttempt::create([
            'quiz_id' => $quiz->id, 'user_id' => $user->id,
            'started_at' => now()->subHour(), 'submitted_at' => now(),
            'score' => $score, 'max_score' => $max, 'passed' => $score >= $max / 2,
        ]);
    }

    protected function assignment(Course $course, int $max = 100): Assignment
    {
        return Assignment::create([
            'course_id' => $course->id, 'title' => 'Essay', 'description' => 'x',
            'due_at' => now()->addWeek(), 'max_score' => $max,
        ]);
    }

    protected function grade(Assignment $assignment, User $user, int $score): AssignmentSubmission
    {
        return AssignmentSubmission::create([
            'assignment_id' => $assignment->id, 'user_id' => $user->id,
            'submitted_at' => now()->subDay(), 'score' => $score, 'graded_at' => now(),
        ]);
    }

    /** @param array<string,int> $counts status => days */
    protected function attendance(User $user, array $counts): void
    {
        $offset = 0;

        foreach ($counts as $status => $days) {
            for ($i = 0; $i < $days; $i++) {
                DailyAttendance::create([
                    'user_id' => $user->id,
                    // One row per day, all after the enrolment date the tests
                    // use, so nothing is filtered out by the enrolment scope.
                    'date' => now()->subDays(50 - $offset++)->toDateString(),
                    'status' => $status,
                    'source' => 'manual',
                    'marked_at' => now(),
                ]);
            }
        }
    }

    /* ---------------------------- the arithmetic ---------------------------- */

    /**
     * All four checked at the defaults, on a student with all four kinds of
     * record. Worked out by hand:
     *
     *   attendance  8 present, 2 absent -> (8*100 + 2*0)/10        = 80.0  x40 = 32.0
     *   quiz        best of 6/10 and 9/10 -> (60 + 90)/2           = 75.0  x20 = 15.0
     *   assignment  90/100 graded                                  = 90.0  x20 = 18.0
     *   premium     2 of 4 lessons complete                        = 50.0  x20 = 10.0
     *                                                              total    = 75.0
     */
    public function test_all_four_at_the_defaults_produce_the_hand_worked_figure(): void
    {
        $this->weights(['attendance' => 40, 'quiz' => 20, 'assignment' => 20, 'premium' => 20]);

        [$course, , $lessons] = $this->makeCourse(4);
        $student = $this->actingAsStudent();
        $this->enroll($student, $course, ['enrolled_at' => now()->subDays(90)]);

        $this->attendance($student, ['present' => 8, 'absent' => 2]);

        $q1 = $this->quiz($course, 'Q1');
        $q2 = $this->quiz($course, 'Q2');
        $this->attempt($q1, $student, 4);   // a worse earlier attempt
        $this->attempt($q1, $student, 6);   // best counts
        $this->attempt($q2, $student, 9);

        $this->grade($this->assignment($course), $student, 90);

        LessonCompletion::create(['user_id' => $student->id, 'lesson_id' => $lessons[0]->id, 'course_id' => $course->id, 'completed_at' => now()]);
        LessonCompletion::create(['user_id' => $student->id, 'lesson_id' => $lessons[1]->id, 'course_id' => $course->id, 'completed_at' => now()]);

        $breakdown = $this->calculator()->breakdown($student->fresh(), $course);
        $byKey = collect($breakdown['components'])->keyBy('key');

        $this->assertSame(80.0, $byKey['attendance']['score']);
        $this->assertSame(75.0, $byKey['quiz']['score'], 'best attempt per quiz, averaged');
        $this->assertSame(90.0, $byKey['assignment']['score']);
        $this->assertSame(50.0, $byKey['premium']['score']);
        $this->assertSame(75.0, $breakdown['total']);
    }

    public function test_unchecking_premium_removes_its_contribution(): void
    {
        // Premium off, assignment raised to 40 by hand: 40 + 20 + 40 = 100.
        $this->weights(
            ['attendance' => 40, 'quiz' => 20, 'assignment' => 40, 'premium' => 20],
            disabled: ['premium'],
        );

        [$course, , $lessons] = $this->makeCourse(4);
        $student = $this->actingAsStudent();
        $this->enroll($student, $course, ['enrolled_at' => now()->subDays(90)]);

        $this->attendance($student, ['present' => 10]);            // 100
        $this->attempt($this->quiz($course), $student, 5);          // 50
        $this->grade($this->assignment($course), $student, 100);    // 100
        LessonCompletion::create(['user_id' => $student->id, 'lesson_id' => $lessons[0]->id, 'course_id' => $course->id, 'completed_at' => now()]);

        $breakdown = $this->calculator()->breakdown($student->fresh(), $course);

        // 100*40 + 50*20 + 100*40 = 9000 / 100 = 90
        $this->assertSame(90.0, $breakdown['total']);
        $this->assertSame(
            ['attendance', 'quiz', 'assignment'],
            collect($breakdown['components'])->pluck('key')->all(),
            'an unchecked component is absent from the breakdown, not shown at 0',
        );
    }

    /* ---------------------------- the no-data rule --------------------------- */

    /**
     * A course that sets NO quizzes: the component is excluded and its weight
     * shared out. 40 + 20 + 20 = 80 remaining, so attendance is worth 40/80.
     *
     *   attendance 100 x (40/80) + assignment 50 x (20/80) + premium 0 x (20/80)
     *   = 50 + 12.5 + 0 = 62.5
     */
    public function test_a_course_with_no_quizzes_excludes_quizzes_and_redistributes(): void
    {
        $this->weights(['attendance' => 40, 'quiz' => 20, 'assignment' => 20, 'premium' => 20]);

        [$course, , $lessons] = $this->makeCourse(4);
        $student = $this->actingAsStudent();
        $this->enroll($student, $course, ['enrolled_at' => now()->subDays(90)]);

        $this->attendance($student, ['present' => 10]);           // 100
        $this->grade($this->assignment($course), $student, 50);   // 50
        // no quizzes at all, no lessons completed

        $breakdown = $this->calculator()->breakdown($student->fresh(), $course);
        $quizRow = collect($breakdown['components'])->firstWhere('key', 'quiz');

        $this->assertTrue($quizRow['excluded'], 'a course with no quizzes cannot score its students on quizzes');
        $this->assertNull($quizRow['score']);
        $this->assertSame(0.0, $quizRow['contribution']);
        $this->assertSame(62.5, $breakdown['total']);

        // The surviving weights add up to 100 between them.
        $this->assertEqualsWithDelta(
            100.0,
            collect($breakdown['components'])->sum('effective_weight'),
            0.05,
        );
    }

    /**
     * The distinction that matters most.
     *
     * The course DOES set quizzes and the student has attempted none. That is a
     * zero, not an exclusion — otherwise skipping every quiz would be worth
     * more than sitting them badly, and ignoring the component would RAISE the
     * student's score.
     *
     *   attendance 100 x40 + quiz 0 x20 + assignment 50 x20 + premium 0 x20
     *   = 4000 + 0 + 1000 + 0 = 5000 / 100 = 50
     */
    public function test_quizzes_set_but_none_attempted_score_zero_and_stay_in(): void
    {
        $this->weights(['attendance' => 40, 'quiz' => 20, 'assignment' => 20, 'premium' => 20]);

        [$course, , $lessons] = $this->makeCourse(4);
        $student = $this->actingAsStudent();
        $this->enroll($student, $course, ['enrolled_at' => now()->subDays(90)]);

        $this->attendance($student, ['present' => 10]);
        $this->quiz($course, 'Untouched one');
        $this->quiz($course, 'Untouched two');
        $this->grade($this->assignment($course), $student, 50);

        $breakdown = $this->calculator()->breakdown($student->fresh(), $course);
        $quizRow = collect($breakdown['components'])->firstWhere('key', 'quiz');

        $this->assertFalse($quizRow['excluded'], 'an unattempted quiz is a zero, not an exclusion');
        $this->assertSame(0.0, $quizRow['score']);
        $this->assertSame(50.0, $breakdown['total']);
    }

    /** The two no-data cases side by side, so the difference cannot be argued away. */
    public function test_no_quizzes_scores_higher_than_quizzes_ignored(): void
    {
        $this->weights(['attendance' => 40, 'quiz' => 20, 'assignment' => 20, 'premium' => 20]);

        $build = function (bool $withQuizzes) {
            [$course, , $lessons] = $this->makeCourse(4);
            $student = User::factory()->create();
            $student->assignRole('student');
            $this->enroll($student, $course, ['enrolled_at' => now()->subDays(90)]);
            $this->attendance($student, ['present' => 10]);
            $this->grade($this->assignment($course), $student, 50);

            if ($withQuizzes) {
                $this->quiz($course);
            }

            return $this->calculator()->percent($student, $course);
        };

        $noQuizzesSet = $build(false);
        $setButSkipped = $build(true);

        $this->assertGreaterThan(
            $setButSkipped,
            $noQuizzesSet,
            'skipping quizzes must never score better than a course that sets none',
        );
    }

    public function test_an_unenrolled_student_with_no_attendance_excludes_attendance(): void
    {
        $this->weights(['attendance' => 40, 'quiz' => 20, 'assignment' => 20, 'premium' => 20]);

        [$course, , $lessons] = $this->makeCourse(2);
        $student = $this->actingAsStudent();
        $this->enroll($student, $course);

        LessonCompletion::create(['user_id' => $student->id, 'lesson_id' => $lessons[0]->id, 'course_id' => $course->id, 'completed_at' => now()]);

        $breakdown = $this->calculator()->breakdown($student->fresh(), $course);
        $row = collect($breakdown['components'])->firstWhere('key', 'attendance');

        // No marked days is no data, not a month of absences.
        $this->assertTrue($row['excluded']);
        $this->assertSame(50.0, $breakdown['total'], 'only premium has data, so it is the whole figure');
    }

    /* ------------------------------ weights move ---------------------------- */

    public function test_changing_a_weight_moves_the_live_figure_at_once(): void
    {
        $this->weights(['attendance' => 40, 'quiz' => 20, 'assignment' => 20, 'premium' => 20]);

        [$course, , $lessons] = $this->makeCourse(4);
        $student = $this->actingAsStudent();
        $this->enroll($student, $course, ['enrolled_at' => now()->subDays(90)]);

        $this->attendance($student, ['present' => 10]);            // 100
        $this->attempt($this->quiz($course), $student, 0);          // 0
        $this->grade($this->assignment($course), $student, 0);      // 0
        // premium 0

        $this->assertSame(40.0, $this->calculator()->percent($student->fresh(), $course));

        // Attendance down to 30, premium up to 30.
        $this->weights(['attendance' => 30, 'quiz' => 20, 'assignment' => 20, 'premium' => 30]);

        $this->assertSame(
            30.0,
            $this->calculator()->percent($student->fresh(), $course),
            'a live surface reflects a weight change with no recompute anywhere',
        );
    }

    /* ------------------------------ certificates ---------------------------- */

    /**
     * Coursework at 100 with poor attendance still earns the certificate.
     *
     * This is the whole reason attendance is excluded from the threshold: it is
     * the one component a student cannot go back and fix.
     */
    public function test_full_coursework_with_poor_attendance_still_issues_the_certificate(): void
    {
        $this->weights(['attendance' => 40, 'quiz' => 20, 'assignment' => 20, 'premium' => 20]);

        [$course, , $lessons] = $this->makeCourse(2);
        $student = $this->actingAsStudent();
        $enrollment = $this->enroll($student, $course, ['enrolled_at' => now()->subDays(90)]);

        $this->attendance($student, ['absent' => 9, 'present' => 1]);   // 10% attendance
        $this->attempt($this->quiz($course), $student, 10);             // 100
        $this->grade($this->assignment($course), $student, 100);        // 100

        // Completing the last lesson takes premium to 100 and fires the sync.
        app(LessonProgressService::class)->complete($student, $course, $lessons[0]);
        app(LessonProgressService::class)->complete($student, $course, $lessons[1]);

        $this->assertSame(100.0, $this->calculator()->courseworkPercent($student->fresh(), $course));
        $this->assertNotNull(
            Certificate::where('user_id', $student->id)->where('course_id', $course->id)->first(),
            'attendance must not stand between a student and a certificate they earned',
        );
        $this->assertNotNull($enrollment->fresh()->completed_at, 'completed_at follows the same rule');

        // And the displayed total is still the all-four figure, well under 100.
        $this->assertLessThan(100.0, $this->calculator()->percent($student->fresh(), $course));
    }

    public function test_an_issued_certificate_is_never_revoked(): void
    {
        $this->weights(['attendance' => 40, 'quiz' => 20, 'assignment' => 20, 'premium' => 20]);

        [$course, , $lessons] = $this->makeCourse(2);
        $student = $this->actingAsStudent();
        $this->enroll($student, $course, ['enrolled_at' => now()->subDays(90)]);

        app(LessonProgressService::class)->complete($student, $course, $lessons[0]);
        app(LessonProgressService::class)->complete($student, $course, $lessons[1]);

        $certificate = Certificate::where('user_id', $student->id)->firstOrFail();

        // Now the course sets a quiz the student has never sat: coursework
        // drops well below 100 and the next recompute runs.
        $this->quiz($course);
        app(LessonProgressService::class)->uncomplete($student, $course, $lessons[1]);
        app(LessonProgressService::class)->complete($student, $course, $lessons[1]);

        $this->assertLessThan(100.0, $this->calculator()->courseworkPercent($student->fresh(), $course));
        $this->assertDatabaseHas('certificates', ['id' => $certificate->id]);
        $this->assertSame(
            $certificate->certificate_number,
            Certificate::find($certificate->id)->certificate_number,
            'an earned certificate keeps its number and its date',
        );
    }

    /* ------------------------------ query counts ---------------------------- */

    public function test_one_students_breakdown_is_a_handful_of_queries(): void
    {
        $this->weights(['attendance' => 40, 'quiz' => 20, 'assignment' => 20, 'premium' => 20]);

        [$course, , $lessons] = $this->makeCourse(4);
        $student = $this->actingAsStudent();
        $this->enroll($student, $course, ['enrolled_at' => now()->subDays(90)]);
        $this->attendance($student, ['present' => 10]);
        $this->attempt($this->quiz($course), $student, 5);
        $this->grade($this->assignment($course), $student, 50);

        $student = $student->fresh();

        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $this->calculator()->breakdown($student, $course);

        // One per component plus the enrolment lookup and the lesson count.
        fwrite(STDERR, "\n  [query count] one student's live breakdown: {$count} queries\n");

        $this->assertLessThanOrEqual(
            8,
            $count,
            "one student's live breakdown took {$count} queries — a live surface must stay cheap",
        );
    }
}
