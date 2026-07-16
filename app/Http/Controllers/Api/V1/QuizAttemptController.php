<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\SubmitQuizAttemptRequest;
use App\Http\Resources\QuizAttemptResource;
use App\Http\Resources\QuizResultResource;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Services\QuizService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class QuizAttemptController extends ApiController
{
    /** Starts (or resumes) an attempt: questions without correct answers. */
    public function store(Request $request, Quiz $quiz, QuizService $quizzes): JsonResponse
    {
        abort_unless($quiz->is_published, 404);
        abort_unless($this->enrolledCourseIds($request)->contains($quiz->course_id), 403);

        $attempt = $quizzes->startAttempt($request->user(), $quiz);

        $attempt->setRelation('quiz', $quiz->load('questions.options'));

        return (new QuizAttemptResource($attempt))
            ->response($request)
            ->setStatusCode($attempt->wasRecentlyCreated ? 201 : 200);
    }

    public function submit(SubmitQuizAttemptRequest $request, Quiz $quiz, QuizAttempt $attempt, QuizService $quizzes): QuizResultResource
    {
        abort_unless($attempt->quiz_id === $quiz->id, 404);
        Gate::authorize('submit', $attempt);

        $attempt = $quizzes->submitAttempt($quiz, $attempt, $request->validated('answers', []));

        return new QuizResultResource($this->loadResult($attempt));
    }

    /** The student's finished attempts for this quiz, newest first. */
    public function index(Request $request, Quiz $quiz): AnonymousResourceCollection
    {
        abort_unless($this->enrolledCourseIds($request)->contains($quiz->course_id), 403);

        $attempts = QuizAttempt::where('quiz_id', $quiz->id)
            ->where('user_id', $request->user()->id)
            ->whereNotNull('submitted_at')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->with(['answers', 'quiz.course', 'quiz.questions.options'])
            ->get();

        return QuizResultResource::collection($attempts);
    }

    public function show(Request $request, Quiz $quiz, QuizAttempt $attempt): QuizResultResource
    {
        abort_unless($attempt->quiz_id === $quiz->id, 404);
        Gate::authorize('view', $attempt);
        abort_if($attempt->submitted_at === null, 404, 'This attempt has not been submitted yet.');

        return new QuizResultResource($this->loadResult($attempt));
    }

    protected function loadResult(QuizAttempt $attempt): QuizAttempt
    {
        return $attempt->load(['answers', 'quiz.course', 'quiz.questions.options']);
    }
}
