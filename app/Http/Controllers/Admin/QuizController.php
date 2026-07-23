<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RestrictsToInstructor;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Quiz;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class QuizController extends Controller
{
    use RestrictsToInstructor;

    public function index(Request $request): View
    {
        $quizzes = Quiz::query()
            ->with(['course', 'lesson'])
            ->withCount(['questions', 'attempts'])
            ->when(($mine = $this->managedCourseIds($request)) !== null, fn ($query) => $query->whereIn('course_id', $mine))
            ->when($request->filled('course'), fn ($query) => $query->where('course_id', $request->integer('course')))
            ->when($request->filled('search'), fn ($query) => $query->where('title', 'like', '%'.trim($request->string('search')).'%'))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('admin.quizzes.index', [
            'quizzes' => $quizzes,
            'courses' => $this->selectableCourses($request)->get(['id', 'title']),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.quizzes.form', [
            'quiz' => null,
            'courses' => $this->selectableCourses($request)->with('lessons:id,course_id,title')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->authorizeCourseAccess($request, (int) $data['course_id']);
        $quiz = Quiz::create($data);

        return redirect()->route('admin.quizzes.show', $quiz)->with('success', "Quiz \"{$quiz->title}\" created — add questions below.");
    }

    /** The quiz builder. */
    public function show(Request $request, Quiz $quiz): View
    {
        $this->authorizeCourseAccess($request, $quiz->course_id);
        $quiz->load(['course', 'lesson', 'questions.options'])->loadCount('attempts');

        return view('admin.quizzes.builder', ['quiz' => $quiz]);
    }

    public function attempts(Request $request, Quiz $quiz): View
    {
        $this->authorizeCourseAccess($request, $quiz->course_id);
        $quiz->load('course')->loadCount('questions');

        $attempts = $quiz->attempts()
            ->with('user')
            ->latest('started_at')
            ->paginate(15);

        return view('admin.quizzes.attempts', ['quiz' => $quiz, 'attempts' => $attempts]);
    }

    public function edit(Request $request, Quiz $quiz): View
    {
        $this->authorizeCourseAccess($request, $quiz->course_id);

        return view('admin.quizzes.form', [
            'quiz' => $quiz,
            'courses' => $this->selectableCourses($request)->with('lessons:id,course_id,title')->get(),
        ]);
    }

    public function update(Request $request, Quiz $quiz): RedirectResponse
    {
        $this->authorizeCourseAccess($request, $quiz->course_id);
        $data = $this->validated($request);
        $this->authorizeCourseAccess($request, (int) $data['course_id']);
        $quiz->update($data);

        return redirect()->route('admin.quizzes.show', $quiz)->with('success', "Quiz \"{$quiz->title}\" updated.");
    }

    public function destroy(Request $request, Quiz $quiz): RedirectResponse
    {
        $this->authorizeCourseAccess($request, $quiz->course_id);
        $title = $quiz->title;
        $quiz->delete();

        return redirect()->route('admin.quizzes.index')->with('success', "Quiz \"{$title}\" deleted.");
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'course_id' => ['required', Rule::exists('courses', 'id')],
            'lesson_id' => ['nullable', Rule::exists('lessons', 'id')->where('course_id', $request->integer('course_id'))],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'attempts_allowed' => ['nullable', 'integer', 'min:1', 'max:50'],
            'passing_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date', 'after_or_equal:available_from'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $data['lesson_id'] = $data['lesson_id'] ?? null;
        $data['is_published'] = $request->boolean('is_published');

        return $data;
    }
}
