<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\QuizResource;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

class QuizController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = Quiz::query()
            ->published()
            ->whereIn('course_id', $this->enrolledCourseIds($request))
            ->with('course')
            ->withCount('questions')
            ->withSum('questions as total_points', 'points');

        if ($courseId = $request->query('course_id')) {
            $query->where('course_id', $courseId);
        }

        if ($status = $request->query('status')) {
            $this->applyStatusFilter($query, $status, $user->id);
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn (Builder $q) => $q
                ->where('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%"));
        }

        $quizzes = $query->orderBy('id')->paginate($this->perPage($request))->withQueryString();

        $this->attachUserState($user->id, collect($quizzes->items()));

        return QuizResource::collection($quizzes);
    }

    public function show(Request $request, Quiz $quiz): QuizResource
    {
        abort_unless($quiz->is_published, 404);
        abort_unless($this->enrolledCourseIds($request)->contains($quiz->course_id), 403);

        $quiz->load('course');
        $quiz->loadCount('questions');
        $quiz->loadSum('questions as total_points', 'points');

        $this->attachUserState($request->user()->id, collect([$quiz]));

        return new QuizResource($quiz);
    }

    /**
     * Computes attempts_used, best_score and status for each quiz from one
     * attempts query.
     *
     * @param  Collection<int, Quiz>  $quizzes
     */
    protected function attachUserState(int $userId, Collection $quizzes): void
    {
        $attempts = QuizAttempt::where('user_id', $userId)
            ->whereIn('quiz_id', $quizzes->pluck('id'))
            ->get()
            ->groupBy('quiz_id');

        foreach ($quizzes as $quiz) {
            $own = $attempts->get($quiz->id, collect());
            $finished = $own->whereNotNull('submitted_at');
            $hasOpen = $own->whereNull('submitted_at')->isNotEmpty();

            $bestScore = $finished
                ->filter(fn (QuizAttempt $attempt) => (int) $attempt->max_score > 0)
                ->map(fn (QuizAttempt $attempt) => round($attempt->score / $attempt->max_score * 100, 2))
                ->max();

            $status = match (true) {
                $hasOpen => 'in_progress',
                $finished->isNotEmpty() => $finished->contains('passed', true) ? 'passed' : 'failed',
                default => 'not_started',
            };

            $quiz->setAttribute('attempts_used', $finished->count());
            $quiz->setAttribute('best_score', $bestScore);
            $quiz->setAttribute('status', $status);
        }
    }

    protected function applyStatusFilter(Builder $query, string $status, int $userId): void
    {
        $mine = fn (Builder $q) => $q->where('user_id', $userId);
        $open = fn (Builder $q) => $mine($q)->whereNull('submitted_at');
        $finished = fn (Builder $q) => $mine($q)->whereNotNull('submitted_at');
        $passed = fn (Builder $q) => $finished($q)->where('passed', true);

        match ($status) {
            'in_progress' => $query->whereHas('attempts', $open),
            'not_started' => $query->whereDoesntHave('attempts', $mine),
            'passed' => $query->whereDoesntHave('attempts', $open)->whereHas('attempts', $passed),
            'failed' => $query->whereDoesntHave('attempts', $open)
                ->whereHas('attempts', $finished)
                ->whereDoesntHave('attempts', $passed),
            default => null,
        };
    }
}
