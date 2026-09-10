<?php

namespace Tests\Feature\Api;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Setting;
use App\Support\QuizRules;
use Illuminate\Support\Carbon;

/**
 * One attempt, and thirty seconds a question.
 *
 * Both are academy settings with a per-quiz override, and the time limit is a
 * RATE rather than a total. That distinction is the whole point: questions are
 * added and removed by the builder, which never touches the quiz row, so a
 * stored whole-quiz total would go stale the first time anyone edited a quiz
 * and nothing would show that it had.
 */
class QuizRulesTest extends ApiTestCase
{
    /** A published quiz on a course, with $questionCount answerable questions. */
    protected function quizWith(int $questionCount, array $overrides = []): array
    {
        [$course] = $this->makeCourse(1);

        $quiz = Quiz::create(array_merge([
            'course_id' => $course->id,
            'title' => 'Knowledge check',
            'seconds_per_question' => null,
            'attempts_allowed' => null,
            'passing_score' => 60,
            'is_published' => true,
        ], $overrides));

        for ($i = 1; $i <= $questionCount; $i++) {
            $question = Question::create([
                'quiz_id' => $quiz->id, 'type' => 'true_false',
                'prompt' => "Question {$i}", 'points' => 1, 'position' => $i,
            ]);
            QuestionOption::create(['question_id' => $question->id, 'text' => 'Yes', 'is_correct' => true, 'position' => 1]);
            QuestionOption::create(['question_id' => $question->id, 'text' => 'No', 'is_correct' => false, 'position' => 2]);
        }

        return [$course, $quiz->fresh()];
    }

    // ------------------------------------------------------------- defaults

    public function test_a_new_quiz_gets_one_attempt_and_thirty_seconds_a_question(): void
    {
        [, $quiz] = $this->quizWith(3);

        $this->assertSame(1, $quiz->allowedAttempts());
        $this->assertSame(30, $quiz->secondsPerQuestion());
        $this->assertSame(90, $quiz->timeLimitSeconds(3));
    }

    public function test_a_ten_question_quiz_runs_for_three_hundred_seconds(): void
    {
        [, $quiz] = $this->quizWith(10);

        $this->assertSame(300, $quiz->timeLimitSeconds());
    }

    public function test_adding_a_question_grows_the_limit_with_no_save(): void
    {
        // The reason the rate is stored and the total is not. Nothing here
        // updates the quiz row — the builder never does either.
        [, $quiz] = $this->quizWith(4);
        $this->assertSame(120, $quiz->timeLimitSeconds());

        Question::create([
            'quiz_id' => $quiz->id, 'type' => 'true_false',
            'prompt' => 'A fifth', 'points' => 1, 'position' => 5,
        ]);

        $this->assertSame(150, $quiz->fresh()->timeLimitSeconds());
    }

    public function test_a_quiz_with_no_questions_still_gets_a_clock(): void
    {
        // Zero questions times any rate is zero, and an attempt that expires
        // before it renders is worse than a pointless one that does not.
        [, $quiz] = $this->quizWith(0);

        $this->assertSame(30, $quiz->timeLimitSeconds());
    }

    // -------------------------------------------------------- the settings

    public function test_an_admin_changing_a_setting_reaches_the_next_quiz(): void
    {
        [, $quiz] = $this->quizWith(4);
        $this->assertSame(120, $quiz->timeLimitSeconds());
        $this->assertSame(1, $quiz->allowedAttempts());

        Setting::updateOrCreate(['key' => 'quiz_seconds_per_question'], ['value' => 45, 'group' => 'general']);
        Setting::updateOrCreate(['key' => 'quiz_default_attempts'], ['value' => 3, 'group' => 'general']);
        Setting::forgetCached();

        // No deploy, no migration, no per-quiz edit.
        $this->assertSame(180, $quiz->fresh()->timeLimitSeconds());
        $this->assertSame(3, $quiz->fresh()->allowedAttempts());
    }

    public function test_a_quiz_that_pins_its_own_numbers_ignores_the_setting(): void
    {
        // The override exists so a final exam can differ from a practice quiz.
        [, $quiz] = $this->quizWith(4, ['seconds_per_question' => 90, 'attempts_allowed' => 2]);

        Setting::updateOrCreate(['key' => 'quiz_seconds_per_question'], ['value' => 10, 'group' => 'general']);
        Setting::updateOrCreate(['key' => 'quiz_default_attempts'], ['value' => 1, 'group' => 'general']);
        Setting::forgetCached();

        $this->assertSame(360, $quiz->fresh()->timeLimitSeconds());
        $this->assertSame(2, $quiz->fresh()->allowedAttempts());
    }

    public function test_a_setting_outside_its_bounds_is_clamped_not_obeyed(): void
    {
        // A settings row can be edited by something other than the form —
        // a seeder, a console command, a hand-written UPDATE — and a zero
        // here would be a quiz nobody can sit.
        Setting::updateOrCreate(['key' => 'quiz_default_attempts'], ['value' => 0, 'group' => 'general']);
        Setting::updateOrCreate(['key' => 'quiz_seconds_per_question'], ['value' => 0, 'group' => 'general']);
        Setting::forgetCached();

        $this->assertSame(QuizRules::MIN_ATTEMPTS, QuizRules::defaultAttempts());
        $this->assertSame(QuizRules::MIN_SECONDS_PER_QUESTION, QuizRules::defaultSecondsPerQuestion());
    }

    // ------------------------------------------------ the server is the rule

    public function test_a_second_attempt_is_refused_by_the_server(): void
    {
        $user = $this->actingAsStudent();
        [$course, $quiz] = $this->quizWith(3);
        $this->enroll($user, $course);

        $first = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->assertSuccessful();
        $attemptId = $first->json('data.id');

        $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts/{$attemptId}/submit", ['answers' => []])
            ->assertSuccessful();

        // Not merely a disabled button: the endpoint itself refuses.
        $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")
            ->assertStatus(422)
            ->assertJsonValidationErrors('quiz');

        $this->assertSame(1, QuizAttempt::where('quiz_id', $quiz->id)->where('user_id', $user->id)->count());
    }

    public function test_the_attempt_clock_is_the_derived_total(): void
    {
        $user = $this->actingAsStudent();
        [$course, $quiz] = $this->quizWith(6);
        $this->enroll($user, $course);

        $started = Carbon::parse('2026-09-10 10:00:00');
        Carbon::setTestNow($started);

        $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->assertSuccessful();

        $attempt = QuizAttempt::where('quiz_id', $quiz->id)->firstOrFail();

        // 6 questions x 30s, on the attempt row — not on the quiz.
        $this->assertSame(180, (int) $started->diffInSeconds($attempt->expires_at));

        Carbon::setTestNow();
    }

    public function test_an_attempt_already_running_keeps_the_clock_it_started_with(): void
    {
        // The mid-deploy case. expires_at is written when the attempt starts,
        // so changing the rate underneath a student cannot shorten the quiz
        // they are sitting.
        $user = $this->actingAsStudent();
        [$course, $quiz] = $this->quizWith(6);
        $this->enroll($user, $course);

        Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00'));
        $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->assertSuccessful();
        $expiry = QuizAttempt::where('quiz_id', $quiz->id)->firstOrFail()->expires_at;

        Setting::updateOrCreate(['key' => 'quiz_seconds_per_question'], ['value' => 5, 'group' => 'general']);
        Setting::forgetCached();

        // Resuming returns the same attempt with the same deadline.
        Carbon::setTestNow(Carbon::parse('2026-09-10 10:01:00'));
        $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->assertSuccessful();

        $this->assertTrue($expiry->equalTo(QuizAttempt::where('quiz_id', $quiz->id)->firstOrFail()->expires_at));
        $this->assertSame(1, QuizAttempt::where('quiz_id', $quiz->id)->count());

        Carbon::setTestNow();
    }

    // ------------------------------------------------------- what the portal reads

    public function test_the_api_sends_the_resolved_rules_not_the_raw_columns(): void
    {
        $user = $this->actingAsStudent();
        [$course, $quiz] = $this->quizWith(10);
        $this->enroll($user, $course);

        // Both columns are NULL here; the portal must still be told 1 and 30.
        $this->getJson("/api/v1/quizzes/{$quiz->id}")
            ->assertOk()
            ->assertJsonPath('data.attempts_allowed', 1)
            ->assertJsonPath('data.seconds_per_question', 30)
            ->assertJsonPath('data.time_limit_seconds', 300);
    }

    public function test_the_dashboard_still_counts_quizzes_on_the_default_allowance(): void
    {
        // attempts_allowed is nullable now, and `count < NULL` is NULL in SQL.
        // Without a COALESCE every quiz on the academy default would have
        // silently vanished from this figure.
        $user = $this->actingAsStudent();
        [$course] = $this->quizWith(3);
        $this->enroll($user, $course);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.stats.pending_quizzes', 1);
    }
}
