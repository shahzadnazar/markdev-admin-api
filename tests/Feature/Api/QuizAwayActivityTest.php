<?php

namespace Tests\Feature\Api;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Recording that a student left the quiz tab.
 *
 * Telemetry, and the tests treat it as such: the last one here unplugs the
 * endpoint entirely and checks the quiz still submits and scores, because a
 * feature that can break grading is not worth having for a signal this weak.
 *
 * The signal is weak. It sees one browser tab losing focus and nothing else —
 * not a phone, not a second device, not a printed page, not someone reading
 * over a shoulder. A notification and a clock check look identical to a
 * deliberate departure. Nothing here decides anything; it records a number an
 * instructor may or may not find interesting.
 */
class QuizAwayActivityTest extends ApiTestCase
{
    /** @return array{0: \App\Models\Course, 1: Quiz} */
    protected function publishedQuiz(int $questions = 4): array
    {
        [$course] = $this->makeCourse(1);

        $quiz = Quiz::create([
            'course_id' => $course->id,
            'title' => 'Knowledge check',
            'seconds_per_question' => 600,
            'attempts_allowed' => null,
            'passing_score' => 50,
            'is_published' => true,
        ]);

        for ($i = 1; $i <= $questions; $i++) {
            $question = Question::create([
                'quiz_id' => $quiz->id, 'type' => 'true_false',
                'prompt' => "Q{$i}", 'points' => 1, 'position' => $i,
            ]);
            QuestionOption::create(['question_id' => $question->id, 'text' => 'Yes', 'is_correct' => true, 'position' => 1]);
            QuestionOption::create(['question_id' => $question->id, 'text' => 'No', 'is_correct' => false, 'position' => 2]);
        }

        return [$course, $quiz];
    }

    /** Start an attempt as an enrolled student. */
    protected function startAttempt(?User $user = null): array
    {
        $user ??= $this->actingAsStudent();
        [$course, $quiz] = $this->publishedQuiz();
        $this->enroll($user, $course);

        $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->assertSuccessful();

        return [$user, $quiz, QuizAttempt::where('quiz_id', $quiz->id)->where('user_id', $user->id)->firstOrFail()];
    }

    protected function report(Quiz $quiz, QuizAttempt $attempt, int $count, int $seconds)
    {
        return $this->postJson(
            "/api/v1/quizzes/{$quiz->id}/attempts/{$attempt->id}/activity",
            ['away_count' => $count, 'away_seconds' => $seconds],
        );
    }

    // ------------------------------------------------------------ recording

    public function test_one_round_trip_is_recorded_once(): void
    {
        [, $quiz, $attempt] = $this->startAttempt();

        Carbon::setTestNow(now()->addSeconds(30));
        $this->report($quiz, $attempt, 1, 12)->assertNoContent();
        Carbon::setTestNow();

        $attempt->refresh();
        $this->assertSame(1, $attempt->away_count);
        $this->assertSame(12, $attempt->away_seconds);
        $this->assertNotNull($attempt->last_away_at);
    }

    public function test_three_round_trips_accumulate(): void
    {
        [, $quiz, $attempt] = $this->startAttempt();
        Carbon::setTestNow(now()->addSeconds(300));

        // The client sends its running totals, so each message supersedes the
        // last rather than adding to it.
        $this->report($quiz, $attempt, 1, 12)->assertNoContent();
        $this->report($quiz, $attempt, 2, 31)->assertNoContent();
        $this->report($quiz, $attempt, 3, 58)->assertNoContent();

        Carbon::setTestNow();
        $attempt->refresh();
        $this->assertSame(3, $attempt->away_count);
        $this->assertSame(58, $attempt->away_seconds);
    }

    public function test_a_retry_does_not_double_count(): void
    {
        // The reason totals are cumulative and the server keeps the larger:
        // these are sent from a tab being hidden, so a duplicate is likely.
        [, $quiz, $attempt] = $this->startAttempt();
        Carbon::setTestNow(now()->addSeconds(300));

        $this->report($quiz, $attempt, 2, 40)->assertNoContent();
        $this->report($quiz, $attempt, 2, 40)->assertNoContent();
        $this->report($quiz, $attempt, 2, 40)->assertNoContent();

        Carbon::setTestNow();
        $attempt->refresh();
        $this->assertSame(2, $attempt->away_count);
        $this->assertSame(40, $attempt->away_seconds);
    }

    public function test_a_late_or_reordered_message_cannot_lower_the_totals(): void
    {
        [, $quiz, $attempt] = $this->startAttempt();
        Carbon::setTestNow(now()->addSeconds(300));

        $this->report($quiz, $attempt, 5, 90)->assertNoContent();
        // An earlier message arriving after a later one.
        $this->report($quiz, $attempt, 2, 30)->assertNoContent();

        Carbon::setTestNow();
        $attempt->refresh();
        $this->assertSame(5, $attempt->away_count);
        $this->assertSame(90, $attempt->away_seconds);
    }

    public function test_never_leaving_records_nothing_and_shows_nothing(): void
    {
        [, , $attempt] = $this->startAttempt();

        $this->assertSame(0, $attempt->away_count);
        $this->assertSame(0, $attempt->away_seconds);
        // Null, not "0 times": an instructor must not be invited to read
        // meaning into the silence of an attempt where nothing was measured.
        $this->assertNull($attempt->awaySummary());
    }

    // --------------------------------------------------------------- limits

    public function test_a_client_claiming_more_away_time_than_elapsed_is_capped(): void
    {
        [, $quiz, $attempt] = $this->startAttempt();

        // Sixty seconds into an attempt, claiming a full day away.
        Carbon::setTestNow(now()->addSeconds(60));
        $this->report($quiz, $attempt, 3, 86400)->assertNoContent();
        Carbon::setTestNow();

        $attempt->refresh();
        $this->assertSame(60, $attempt->away_seconds, 'never more than the attempt has existed');
        $this->assertSame(3, $attempt->away_count);
    }

    public function test_an_absurd_switch_count_is_capped_too(): void
    {
        // A round trip cannot be shorter than the client-side floor, so the
        // elapsed time bounds the count as well as the seconds.
        [, $quiz, $attempt] = $this->startAttempt();

        Carbon::setTestNow(now()->addSeconds(10));
        $this->report($quiz, $attempt, 500000, 5)->assertNoContent();
        Carbon::setTestNow();

        $attempt->refresh();
        $this->assertSame(10, $attempt->away_count);
        $this->assertSame(5, $attempt->away_seconds);
    }

    public function test_away_time_cannot_exceed_the_attempts_own_expiry(): void
    {
        // Long after the clock ran out, the window is still the window.
        [, $quiz, $attempt] = $this->startAttempt();
        $window = (int) $attempt->started_at->diffInSeconds($attempt->expires_at);

        Carbon::setTestNow($attempt->expires_at->copy()->addDay());
        $this->report($quiz, $attempt, 2, 999999)->assertNoContent();
        Carbon::setTestNow();

        $this->assertSame($window, $attempt->refresh()->away_seconds);
    }

    public function test_negative_numbers_are_refused_by_validation(): void
    {
        [, $quiz, $attempt] = $this->startAttempt();

        $this->report($quiz, $attempt, -1, 10)->assertJsonValidationErrors('away_count');
        $this->report($quiz, $attempt, 1, -10)->assertJsonValidationErrors('away_seconds');
    }

    // ------------------------------------------------------------- who may

    public function test_another_student_cannot_write_to_this_attempt(): void
    {
        [, $quiz, $attempt] = $this->startAttempt();

        $intruder = $this->student();
        $this->enroll($intruder, $quiz->course);
        $this->actingAsStudent($intruder);

        $this->report($quiz, $attempt, 99, 999)->assertForbidden();

        $this->assertSame(0, $attempt->refresh()->away_count);
    }

    public function test_a_submitted_attempt_cannot_be_edited_afterwards(): void
    {
        [, $quiz, $attempt] = $this->startAttempt();

        $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts/{$attempt->id}/submit", ['answers' => []])
            ->assertSuccessful();

        // The record is closed. Otherwise it would be editable after the fact
        // by the only person it describes.
        $this->report($quiz, $attempt, 4, 100)
            ->assertStatus(422)
            ->assertJsonValidationErrors('attempt');

        $this->assertSame(0, $attempt->refresh()->away_count);
    }

    public function test_an_attempt_from_another_quiz_is_not_found(): void
    {
        [, $quiz, $attempt] = $this->startAttempt();
        [, $otherQuiz] = $this->publishedQuiz();

        $this->postJson(
            "/api/v1/quizzes/{$otherQuiz->id}/attempts/{$attempt->id}/activity",
            ['away_count' => 1, 'away_seconds' => 5],
        )->assertNotFound();
    }

    // -------------------------------------------------- never a dependency

    public function test_the_quiz_still_submits_and_scores_if_the_beacon_never_arrives(): void
    {
        // The whole endpoint might as well not exist: nothing was ever posted
        // to it here, and the attempt grades exactly as it would have.
        $user = $this->actingAsStudent();
        [$course, $quiz] = $this->publishedQuiz(2);
        $this->enroll($user, $course);

        $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->assertSuccessful();
        $attempt = QuizAttempt::where('quiz_id', $quiz->id)->firstOrFail();

        $answers = $quiz->questions()->with('options')->get()
            ->map(fn ($question) => [
                'question_id' => $question->id,
                'selected_option_ids' => [$question->options->firstWhere('is_correct', true)->id],
            ])->all();

        $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts/{$attempt->id}/submit", ['answers' => $answers])
            ->assertSuccessful()
            ->assertJsonPath('data.score', 2)
            ->assertJsonPath('data.passed', true);

        $attempt->refresh();
        $this->assertSame(0, $attempt->away_count);
        $this->assertNotNull($attempt->submitted_at);
    }

    public function test_the_student_is_never_told_any_of_this(): void
    {
        [, $quiz, $attempt] = $this->startAttempt();
        Carbon::setTestNow(now()->addSeconds(300));
        $this->report($quiz, $attempt, 6, 252)->assertNoContent();
        Carbon::setTestNow();

        // The in-flight attempt payload, on resume.
        $resume = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->assertSuccessful();
        foreach (['away_count', 'away_seconds', 'last_away_at'] as $key) {
            $this->assertStringNotContainsString($key, $resume->getContent());
        }

        // And the graded result the student reads afterwards.
        $result = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts/{$attempt->id}/submit", ['answers' => []])
            ->assertSuccessful();
        foreach (['away_count', 'away_seconds', 'last_away_at'] as $key) {
            $this->assertStringNotContainsString($key, $result->getContent());
        }

        $history = $this->getJson("/api/v1/quizzes/{$quiz->id}/attempts")->assertOk();
        foreach (['away_count', 'away_seconds', 'last_away_at'] as $key) {
            $this->assertStringNotContainsString($key, $history->getContent());
        }
    }
}
