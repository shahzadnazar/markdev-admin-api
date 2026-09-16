<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\DailyAttendance;
use App\Models\Enrollment;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Setting;
use App\Models\User;
use App\Services\LessonProgressService;
use App\Support\ProgressWeights;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsSettingsPayload;

/**
 * enrollments.progress_percent is a cache, kept fresh on the event.
 *
 * There is no nightly job. Each test changes one component record and asserts
 * the cached figure moved in the same request — because the alternative, a
 * list screen quietly a day behind the student's own page, is two surfaces
 * disagreeing about the same person.
 */
class ProgressCacheTest extends ApiTestCase
{
    use BuildsSettingsPayload;

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

    protected function cached(User $user, $course): float
    {
        return (float) Enrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->value('progress_percent');
    }

    /* --------------------------- the write paths ---------------------------- */

    public function test_completing_a_lesson_recomputes_the_cache_at_once(): void
    {
        $this->weights(['attendance' => 40, 'quiz' => 20, 'assignment' => 20, 'premium' => 20]);

        [$course, , $lessons] = $this->makeCourse(4);
        $student = $this->actingAsStudent();
        $this->enroll($student, $course);

        $this->assertSame(0.0, $this->cached($student, $course));

        app(LessonProgressService::class)->complete($student, $course, $lessons[0]);

        // Only premium has data, so it is the whole figure: 1 of 4 = 25.
        $this->assertSame(25.0, $this->cached($student, $course), 'no job, no wait — the same request');
    }

    public function test_submitting_a_quiz_recomputes_the_cache_at_once(): void
    {
        $this->weights(['attendance' => 40, 'quiz' => 20, 'assignment' => 20, 'premium' => 20]);

        [$course] = $this->makeCourse(2);
        $student = $this->actingAsStudent();
        $this->enroll($student, $course);

        $quiz = Quiz::create([
            'course_id' => $course->id, 'title' => 'Q', 'description' => 'x',
            'attempts_allowed' => 1, 'passing_score' => 50, 'is_published' => true,
        ]);

        // Quizzes exist and none attempted: quiz scores 0, premium 0 -> 0.
        app(\App\Services\ProgressCache::class)->refresh($student, $course);
        $this->assertSame(0.0, $this->cached($student, $course));

        QuizAttempt::create([
            'quiz_id' => $quiz->id, 'user_id' => $student->id,
            'started_at' => now()->subHour(), 'submitted_at' => now(),
            'score' => 8, 'max_score' => 10, 'passed' => true,
        ]);

        // quiz 80 x20 + premium 0 x20 over 40 = 40.
        $this->assertSame(40.0, $this->cached($student, $course));
    }

    public function test_an_unsubmitted_attempt_does_not_move_anything(): void
    {
        $this->weights(['attendance' => 40, 'quiz' => 20, 'assignment' => 20, 'premium' => 20]);

        [$course] = $this->makeCourse(2);
        $student = $this->actingAsStudent();
        $this->enroll($student, $course);

        $quiz = Quiz::create([
            'course_id' => $course->id, 'title' => 'Q', 'description' => 'x',
            'attempts_allowed' => 1, 'passing_score' => 50, 'is_published' => true,
        ]);

        QuizAttempt::create([
            'quiz_id' => $quiz->id, 'user_id' => $student->id,
            'started_at' => now(), 'submitted_at' => null, 'score' => 0, 'max_score' => 10,
        ]);

        $this->assertSame(0.0, $this->cached($student, $course), 'an attempt in progress has earned nothing');
    }

    public function test_grading_an_assignment_recomputes_the_cache_at_once(): void
    {
        $this->weights(['attendance' => 40, 'quiz' => 20, 'assignment' => 20, 'premium' => 20]);

        [$course] = $this->makeCourse(2);
        $student = $this->actingAsStudent();
        $this->enroll($student, $course);

        $assignment = Assignment::create([
            'course_id' => $course->id, 'title' => 'Essay', 'description' => 'x',
            'due_at' => now()->addWeek(), 'max_score' => 100,
        ]);

        $submission = AssignmentSubmission::create([
            'assignment_id' => $assignment->id, 'user_id' => $student->id,
            'submitted_at' => now(),
        ]);

        // Submitted but ungraded earns nothing yet.
        $this->assertSame(0.0, $this->cached($student, $course));

        $submission->update(['score' => 75, 'graded_at' => now()]);

        // assignment 75 x20 + premium 0 x20 over 40 = 37.5.
        $this->assertSame(37.5, $this->cached($student, $course), 'the figure moves when the instructor grades');
    }

    /**
     * The one component another person moves.
     *
     * An instructor marks the register and the student's cached figure changes
     * without the student doing anything — which is why the portal also
     * refetches on focus and on an interval rather than only after its own
     * mutations.
     */
    public function test_marking_attendance_recomputes_every_enrolled_course(): void
    {
        $this->weights(['attendance' => 40, 'quiz' => 20, 'assignment' => 20, 'premium' => 20]);

        [$courseA] = $this->makeCourse(2);
        [$courseB] = $this->makeCourse(2);
        $student = $this->actingAsStudent();
        $this->enroll($student, $courseA, ['enrolled_at' => now()->subDays(30)]);
        $this->enroll($student, $courseB, ['enrolled_at' => now()->subDays(30)]);

        $this->assertSame(0.0, $this->cached($student, $courseA));
        $this->assertSame(0.0, $this->cached($student, $courseB));

        DailyAttendance::create([
            'user_id' => $student->id, 'date' => now()->subDay()->toDateString(),
            'status' => 'present', 'source' => 'manual', 'marked_at' => now(),
        ]);

        // Attendance 100 with premium 0: 100x40 + 0x20 over 60 = 66.67.
        $this->assertSame(66.67, $this->cached($student, $courseA));
        $this->assertSame(66.67, $this->cached($student, $courseB), 'attendance is academy-wide, so both move');
    }

    /* -------------------------- the weights change -------------------------- */

    public function test_saving_the_settings_recaches_every_enrolment(): void
    {
        $this->weights(['attendance' => 40, 'quiz' => 20, 'assignment' => 20, 'premium' => 20]);

        [$course, , $lessons] = $this->makeCourse(4);
        $student = $this->actingAsStudent();
        $this->enroll($student, $course, ['enrolled_at' => now()->subDays(30)]);

        DailyAttendance::create([
            'user_id' => $student->id, 'date' => now()->subDay()->toDateString(),
            'status' => 'present', 'source' => 'manual', 'marked_at' => now(),
        ]);
        app(LessonProgressService::class)->complete($student, $course, $lessons[0]);

        // attendance 100 x40 + premium 25 x20 over 60 = 75.0
        $this->assertSame(75.0, $this->cached($student, $course));

        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        // Attendance down to 20, premium up to 40.
        $this->actingAs($admin)->put(route('admin.settings.update'), $this->settingsPayload([
            'progress_weight_attendance' => 20,
            'progress_weight_quiz' => 20,
            'progress_weight_assignment' => 20,
            'progress_weight_premium' => 40,
        ]))->assertSessionHasNoErrors();

        Setting::forgetCached();

        // 100x20 + 25x40 over 60 = 50.0 — the LIST figure moved with the save,
        // not when the student next happened to do something.
        $this->assertSame(50.0, $this->cached($student->fresh(), $course));
    }

    /* ---------------------------- the query count --------------------------- */

    /**
     * An admin list of fifty students is why the cache exists.
     *
     * Fifty live calculations would be roughly 350 queries. Reading the cached
     * column is a handful, whatever the row count.
     */
    public function test_an_admin_list_of_fifty_students_stays_cheap(): void
    {
        $this->weights(['attendance' => 40, 'quiz' => 20, 'assignment' => 20, 'premium' => 20]);

        [$course] = $this->makeCourse(2);

        for ($i = 0; $i < 50; $i++) {
            $student = User::factory()->create();
            $student->assignRole('student');
            $this->enroll($student, $course);
        }

        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $this->actingAs($admin)->get(route('admin.enrollments.index'))->assertOk();

        fwrite(STDERR, "\n  [query count] admin enrolment list, 50 students: {$count} queries\n");

        $this->assertLessThan(
            60,
            $count,
            "the admin list took {$count} queries — it must read the cache, not recompute per row",
        );
    }

    public function test_one_lesson_completion_stays_cheap(): void
    {
        $this->weights(['attendance' => 40, 'quiz' => 20, 'assignment' => 20, 'premium' => 20]);

        [$course, , $lessons] = $this->makeCourse(4);
        $student = $this->actingAsStudent();
        $this->enroll($student, $course);

        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        app(LessonProgressService::class)->complete($student, $course, $lessons[0]);

        fwrite(STDERR, "\n  [query count] one lesson completion incl. recompute: {$count} queries\n");

        $this->assertLessThan(
            30,
            $count,
            "completing a lesson took {$count} queries — the recompute must not be a page of them",
        );
    }
}
