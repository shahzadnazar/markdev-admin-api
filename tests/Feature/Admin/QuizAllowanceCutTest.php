<?php

namespace Tests\Feature\Admin;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lowering the allowance is not retroactive.
 *
 * A student who sat a quiz twice under a 2-attempt rule keeps both attempts,
 * keeps the pass, and keeps the points that pass earned. The new number says
 * what a FUTURE attempt gets, and for them the answer is none — which is a
 * different thing from their history being wrong.
 *
 * The trap worth a test is the arithmetic: used(2) against allowed(1) makes
 * "remaining" negative, and a page that prints that, or that clamps the 2 down
 * to 1 to make it tidy, is lying in one direction or the other.
 */
class QuizAllowanceCutTest extends TestCase
{
    use RefreshDatabase;

    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->student = User::factory()->create();
        $this->student->assignRole('student');
    }

    /** A quiz that allowed 2, sat twice, passed on the second. */
    protected function historicQuiz(): Quiz
    {
        $course = \App\Models\Course::create([
            'title' => 'Legacy course', 'slug' => 'legacy-'.Str::random(6),
            'excerpt' => 'x', 'level' => 'beginner', 'status' => 'published',
            'published_at' => now()->subMonth(), 'is_free' => true,
            'category_id' => \App\Models\Category::create(['name' => 'Web', 'slug' => 'web-'.Str::random(4)])->id,
        ]);

        $quiz = Quiz::create([
            'course_id' => $course->id, 'title' => 'Old rules quiz',
            'seconds_per_question' => null, 'attempts_allowed' => 2,
            'passing_score' => 60, 'is_published' => true,
        ]);

        $question = Question::create([
            'quiz_id' => $quiz->id, 'type' => 'true_false',
            'prompt' => 'Q1', 'points' => 5, 'position' => 1,
        ]);
        QuestionOption::create(['question_id' => $question->id, 'text' => 'Yes', 'is_correct' => true, 'position' => 1]);

        foreach ([[2, false], [5, true]] as [$score, $passed]) {
            QuizAttempt::create([
                'quiz_id' => $quiz->id, 'user_id' => $this->student->id,
                'started_at' => now()->subDays(3), 'submitted_at' => now()->subDays(3),
                'score' => $score, 'max_score' => 5, 'passed' => $passed,
            ]);
        }

        return $quiz->fresh();
    }

    public function test_history_survives_the_cut(): void
    {
        $quiz = $this->historicQuiz();

        // The academy default drops to one, and the quiz stops overriding it.
        Setting::updateOrCreate(['key' => 'quiz_default_attempts'], ['value' => 1, 'group' => 'general']);
        Setting::forgetCached();
        $quiz->update(['attempts_allowed' => null]);

        $attempts = QuizAttempt::where('quiz_id', $quiz->id)->where('user_id', $this->student->id)->get();

        $this->assertCount(2, $attempts, 'both attempts must still exist');
        $this->assertTrue($attempts->contains('passed', true), 'the passed attempt is still a pass');
        $this->assertSame(5, (int) $attempts->firstWhere('passed', true)->score);
        $this->assertSame(1, $quiz->fresh()->allowedAttempts());
    }

    public function test_the_student_has_none_left_rather_than_a_negative_number(): void
    {
        $quiz = $this->historicQuiz();
        Setting::updateOrCreate(['key' => 'quiz_default_attempts'], ['value' => 1, 'group' => 'general']);
        Setting::forgetCached();
        $quiz->update(['attempts_allowed' => null]);

        $used = QuizAttempt::where('quiz_id', $quiz->id)
            ->where('user_id', $this->student->id)
            ->whereNotNull('submitted_at')->count();

        $this->assertSame(2, $used);
        $this->assertSame(1, $quiz->fresh()->allowedAttempts());
        // What the portal renders from. Negative is the bug this guards.
        $this->assertSame(0, max(0, $quiz->fresh()->allowedAttempts() - $used));
    }

    public function test_the_server_refuses_another_attempt_for_that_student(): void
    {
        $quiz = $this->historicQuiz();
        Setting::updateOrCreate(['key' => 'quiz_default_attempts'], ['value' => 1, 'group' => 'general']);
        Setting::forgetCached();
        $quiz->update(['attempts_allowed' => null]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(\App\Services\QuizService::class)->startAttempt($this->student->fresh(), $quiz->fresh());
    }

    public function test_the_points_that_pass_earned_are_not_clawed_back(): void
    {
        // Leaderboard and progress both read from points, and re-grading
        // history would move a student down a table they earned their place in.
        $quiz = $this->historicQuiz();
        $this->student->increment('points', \App\Services\QuizService::POINTS_QUIZ_PASSED);
        $before = (int) $this->student->fresh()->points;

        Setting::updateOrCreate(['key' => 'quiz_default_attempts'], ['value' => 1, 'group' => 'general']);
        Setting::forgetCached();
        $quiz->update(['attempts_allowed' => null]);

        $this->assertSame($before, (int) $this->student->fresh()->points);
    }

    public function test_an_admin_can_change_both_defaults_from_the_settings_form(): void
    {
        // The whole point of making these settings: no redeploy, no migration.
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $quiz = $this->historicQuiz();
        $quiz->update(['attempts_allowed' => null, 'seconds_per_question' => null]);

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), $this->settingsPayload([
                'quiz_default_attempts' => 4,
                'quiz_seconds_per_question' => 45,
            ]))
            ->assertRedirect();

        Setting::forgetCached();

        $this->assertSame(4, $quiz->fresh()->allowedAttempts());
        $this->assertSame(45, $quiz->fresh()->secondsPerQuestion());
        $this->assertSame(45, $quiz->fresh()->timeLimitSeconds());
    }

    public function test_the_settings_form_refuses_a_zero_allowance(): void
    {
        // Zero attempts is not an allowance, it is a quiz nobody can sit, and
        // unpublishing is the way to say that.
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        $this->actingAs($admin)
            ->from(route('admin.settings.edit'))
            ->put(route('admin.settings.update'), $this->settingsPayload(['quiz_default_attempts' => 0]))
            ->assertSessionHasErrors('quiz_default_attempts');

        $this->actingAs($admin)
            ->from(route('admin.settings.edit'))
            ->put(route('admin.settings.update'), $this->settingsPayload(['quiz_seconds_per_question' => 1]))
            ->assertSessionHasErrors('quiz_seconds_per_question');
    }

    /** @return array<string, mixed> */
    protected function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'site_name' => 'MarkDev',
            'registration_fee' => 2000,
            'defaulter_fine_per_day' => 100,
            'billing_grace_days' => 5,
            'billing_activation_days' => 5,
            'attendance_day_start_hour' => 9,
            'attendance_day_start_minute' => 0,
            'attendance_day_start_meridiem' => 'AM',
            'attendance_late_after_minutes' => 15,
            'academy_working_days' => [1, 2, 3, 4, 5],
            'holiday_announce_days_before' => 1,
            'attendance_weight_present' => 100,
            'attendance_weight_late' => 70,
            'attendance_weight_leave' => 50,
            'attendance_weight_excused' => 50,
            'attendance_weight_absent' => 0,
            'monthly_leave_allowance' => 2,
            'monthly_absent_allowance' => 2,
            'absent_fine_amount' => 500,
            'attendance_mode' => 'manual',
            'quiz_default_attempts' => 1,
            'quiz_seconds_per_question' => 30,
        ], $overrides);
    }

    public function test_the_migration_left_no_quiz_without_a_limit(): void
    {
        // The old column was nullable and "no limit" was a real state; the new
        // model has no such state, because a NULL rate falls back to the
        // academy default rather than to nothing.
        $quiz = $this->historicQuiz();
        $quiz->update(['seconds_per_question' => null]);

        $this->assertGreaterThan(0, $quiz->fresh()->timeLimitSeconds());
        $this->assertGreaterThan(0, $quiz->fresh()->secondsPerQuestion());
    }
}
