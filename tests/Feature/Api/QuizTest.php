<?php

namespace Tests\Feature\Api;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

class QuizTest extends ApiTestCase
{
    /**
     * A quiz with one of each auto-gradable question type:
     *  - single_choice (2 pts), true_false (1 pt), multiple_choice (3 pts), short_answer (2 pts)
     *
     * @return array{0: \App\Models\Course, 1: Quiz, 2: array<string, mixed>}
     */
    protected function makeQuiz(array $overrides = []): array
    {
        [$course] = $this->makeCourse(1);

        $quiz = Quiz::create(array_merge([
            'course_id' => $course->id,
            'title' => 'Knowledge check',
            'time_limit_minutes' => 15,
            'attempts_allowed' => 2,
            'passing_score' => 60,
            'is_published' => true,
        ], $overrides));

        $single = Question::create(['quiz_id' => $quiz->id, 'type' => 'single_choice', 'prompt' => 'Pick one', 'points' => 2, 'position' => 1, 'explanation' => 'Because.']);
        $singleCorrect = QuestionOption::create(['question_id' => $single->id, 'text' => 'Right', 'is_correct' => true, 'position' => 1]);
        $singleWrong = QuestionOption::create(['question_id' => $single->id, 'text' => 'Wrong', 'is_correct' => false, 'position' => 2]);

        $bool = Question::create(['quiz_id' => $quiz->id, 'type' => 'true_false', 'prompt' => 'True or false', 'points' => 1, 'position' => 2]);
        $boolWrong = QuestionOption::create(['question_id' => $bool->id, 'text' => 'True', 'is_correct' => false, 'position' => 1]);
        $boolCorrect = QuestionOption::create(['question_id' => $bool->id, 'text' => 'False', 'is_correct' => true, 'position' => 2]);

        $multi = Question::create(['quiz_id' => $quiz->id, 'type' => 'multiple_choice', 'prompt' => 'Pick all', 'points' => 3, 'position' => 3]);
        $multiA = QuestionOption::create(['question_id' => $multi->id, 'text' => 'A', 'is_correct' => true, 'position' => 1]);
        $multiB = QuestionOption::create(['question_id' => $multi->id, 'text' => 'B', 'is_correct' => true, 'position' => 2]);
        $multiC = QuestionOption::create(['question_id' => $multi->id, 'text' => 'C', 'is_correct' => false, 'position' => 3]);

        $short = Question::create(['quiz_id' => $quiz->id, 'type' => 'short_answer', 'prompt' => 'Explain', 'points' => 2, 'position' => 4]);

        return [$course, $quiz, compact(
            'single', 'singleCorrect', 'singleWrong',
            'bool', 'boolCorrect', 'boolWrong',
            'multi', 'multiA', 'multiB', 'multiC',
            'short',
        )];
    }

    public function test_quiz_list_and_detail_expose_user_state(): void
    {
        $user = $this->actingAsStudent();
        [$course, $quiz] = $this->makeQuiz();
        $this->enroll($user, $course);

        QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'user_id' => $user->id,
            'started_at' => now()->subHour(),
            'submitted_at' => now()->subHour(),
            'score' => 3,
            'max_score' => 8,
            'passed' => false,
        ]);

        $this->getJson('/api/v1/quizzes')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.attempts_used', 1)
            ->assertJsonPath('data.0.best_score', 37.5)
            ->assertJsonPath('data.0.questions_count', 4)
            ->assertJsonPath('data.0.total_points', 8);

        $this->getJson("/api/v1/quizzes/{$quiz->id}")->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.total_points', 8);

        $this->getJson('/api/v1/quizzes?status=failed')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/quizzes?status=passed')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_starting_an_attempt_returns_questions_without_answers(): void
    {
        $user = $this->actingAsStudent();
        [$course, $quiz] = $this->makeQuiz();
        $this->enroll($user, $course);

        $response = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->assertCreated();

        $response->assertJsonPath('data.quiz_id', $quiz->id)
            ->assertJsonCount(4, 'data.questions')
            ->assertJsonStructure(['data' => ['id', 'started_at', 'expires_at', 'questions' => [['id', 'type', 'prompt', 'points', 'position', 'options']]]]);

        $this->assertNotNull($response->json('data.expires_at'));
        $this->assertStringNotContainsString('is_correct', $response->getContent());
    }

    public function test_starting_again_resumes_the_open_attempt(): void
    {
        $user = $this->actingAsStudent();
        [$course, $quiz] = $this->makeQuiz();
        $this->enroll($user, $course);

        $first = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->assertCreated()->json('data.id');
        $second = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, QuizAttempt::count());
    }

    public function test_attempts_are_blocked_when_exhausted_or_unavailable(): void
    {
        $user = $this->actingAsStudent();
        [$course, $quiz] = $this->makeQuiz(['attempts_allowed' => 1]);
        $this->enroll($user, $course);

        QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'user_id' => $user->id,
            'started_at' => now()->subHour(),
            'submitted_at' => now()->subHour(),
            'score' => 8,
            'max_score' => 8,
            'passed' => true,
        ]);

        $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")
            ->assertStatus(422)
            ->assertJsonValidationErrors('quiz');

        [$closedCourse, $closedQuiz] = $this->makeQuiz(['available_until' => now()->subDay()]);
        $this->enroll($user, $closedCourse);

        $this->postJson("/api/v1/quizzes/{$closedQuiz->id}/attempts")->assertStatus(422);
    }

    public function test_attempts_require_enrollment(): void
    {
        $this->actingAsStudent();
        [, $quiz] = $this->makeQuiz();

        $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->assertForbidden();
    }

    public function test_full_grading_flow_with_all_or_nothing_multiple_choice(): void
    {
        $user = $this->actingAsStudent();
        [$course, $quiz, $q] = $this->makeQuiz();
        $this->enroll($user, $course);

        $attemptId = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->json('data.id');

        // single: correct (2), true_false: wrong (0),
        // multiple: partial selection -> all-or-nothing 0, short: manual 0.
        $response = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts/{$attemptId}/submit", [
            'answers' => [
                ['question_id' => $q['single']->id, 'selected_option_ids' => [$q['singleCorrect']->id]],
                ['question_id' => $q['bool']->id, 'selected_option_ids' => [$q['boolWrong']->id]],
                ['question_id' => $q['multi']->id, 'selected_option_ids' => [$q['multiA']->id, $q['multiC']->id]],
                ['question_id' => $q['short']->id, 'answer_text' => 'My take.'],
            ],
        ])->assertOk();

        $response->assertJsonPath('data.score', 2)
            ->assertJsonPath('data.max_score', 8)
            ->assertJsonPath('data.percent', 25)
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.quiz_title', 'Knowledge check')
            ->assertJsonPath('data.questions.0.is_correct', true)
            ->assertJsonPath('data.questions.0.points_awarded', 2)
            ->assertJsonPath('data.questions.0.correct_option_ids.0', $q['singleCorrect']->id)
            ->assertJsonPath('data.questions.0.explanation', 'Because.')
            ->assertJsonPath('data.questions.2.is_correct', false)
            ->assertJsonPath('data.questions.2.points_awarded', 0)
            ->assertJsonPath('data.questions.3.answer_text', 'My take.')
            ->assertJsonPath('data.questions.3.points_awarded', 0);

        // Failed: no points awarded.
        $this->assertSame(0, $user->fresh()->points);

        // Already submitted: second submit rejected.
        $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts/{$attemptId}/submit", ['answers' => []])
            ->assertStatus(422);
    }

    public function test_passing_a_quiz_awards_points_and_exact_multiple_choice_scores(): void
    {
        $user = $this->actingAsStudent();
        [$course, $quiz, $q] = $this->makeQuiz();
        $this->enroll($user, $course);

        $attemptId = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->json('data.id');

        // 2 + 1 + 3 of 8 => 75% >= 60 passing.
        $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts/{$attemptId}/submit", [
            'answers' => [
                ['question_id' => $q['single']->id, 'selected_option_ids' => [$q['singleCorrect']->id]],
                ['question_id' => $q['bool']->id, 'selected_option_ids' => [$q['boolCorrect']->id]],
                ['question_id' => $q['multi']->id, 'selected_option_ids' => [$q['multiB']->id, $q['multiA']->id]],
            ],
        ])->assertOk()
            ->assertJsonPath('data.score', 6)
            ->assertJsonPath('data.percent', 75)
            ->assertJsonPath('data.passed', true);

        $this->assertSame(20, $user->fresh()->points);
    }

    public function test_attempt_ownership_is_enforced(): void
    {
        $owner = $this->student();
        [$course, $quiz] = $this->makeQuiz();
        $this->enroll($owner, $course);

        $attempt = QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'user_id' => $owner->id,
            'started_at' => now(),
        ]);

        $intruder = $this->student();
        $this->enroll($intruder, $course);
        Sanctum::actingAs($intruder);

        $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts/{$attempt->id}/submit", ['answers' => []])
            ->assertForbidden();
        $this->getJson("/api/v1/quizzes/{$quiz->id}/attempts/{$attempt->id}")->assertForbidden();
    }

    public function test_results_endpoints_return_finished_attempts_only(): void
    {
        $user = $this->actingAsStudent();
        [$course, $quiz, $q] = $this->makeQuiz();
        $this->enroll($user, $course);

        $open = QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'user_id' => $user->id,
            'started_at' => now(),
        ]);

        $this->getJson("/api/v1/quizzes/{$quiz->id}/attempts")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/quizzes/{$quiz->id}/attempts/{$open->id}")->assertNotFound();

        $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts/{$open->id}/submit", [
            'answers' => [
                ['question_id' => $q['single']->id, 'selected_option_ids' => [$q['singleCorrect']->id]],
            ],
        ])->assertOk();

        $this->getJson("/api/v1/quizzes/{$quiz->id}/attempts")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open->id)
            ->assertJsonStructure(['data' => [['questions' => [['correct_option_ids', 'selected_option_ids', 'is_correct', 'points_awarded']]]]]);

        $this->getJson("/api/v1/quizzes/{$quiz->id}/attempts/{$open->id}")->assertOk()
            ->assertJsonPath('data.id', $open->id);
    }
}
