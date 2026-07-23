<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RestrictsToInstructor;
use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\Quiz;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuestionController extends Controller
{
    use RestrictsToInstructor;

    public function store(Request $request, Quiz $quiz): RedirectResponse
    {
        $this->authorizeCourseAccess($request, $quiz->course_id);
        $data = $this->validated($request);

        $question = $quiz->questions()->create([
            'type' => $data['type'],
            'prompt' => $data['prompt'],
            'points' => $data['points'],
            'explanation' => $data['explanation'] ?? null,
            'position' => ((int) $quiz->questions()->max('position')) + 1,
        ]);

        $this->syncOptions($question, $data);

        return back()->with('success', 'Question added.');
    }

    public function update(Request $request, Question $question): RedirectResponse
    {
        $this->authorizeCourseAccess($request, $question->quiz?->course_id);
        $data = $this->validated($request);

        $question->update([
            'type' => $data['type'],
            'prompt' => $data['prompt'],
            'points' => $data['points'],
            'explanation' => $data['explanation'] ?? null,
        ]);

        $this->syncOptions($question, $data, replace: true);

        return back()->with('success', 'Question saved.');
    }

    public function destroy(Question $question): RedirectResponse
    {
        $this->authorizeCourseAccess(request(), $question->quiz?->course_id);
        $question->options()->delete();
        $question->delete();

        return back()->with('success', 'Question deleted.');
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        $type = $request->string('type')->toString();
        $needsOptions = in_array($type, ['single_choice', 'multiple_choice', 'true_false'], true);

        $data = $request->validate([
            'type' => ['required', Rule::in(['single_choice', 'multiple_choice', 'true_false', 'short_answer'])],
            'prompt' => ['required', 'string', 'max:2000'],
            'points' => ['required', 'integer', 'min:1', 'max:100'],
            'explanation' => ['nullable', 'string', 'max:2000'],
            'options' => [$needsOptions ? 'required' : 'nullable', 'array', $needsOptions ? 'min:2' : 'max:0'],
            'options.*.text' => ['required_with:options', 'string', 'max:500'],
            'correct' => [$type === 'multiple_choice' ? 'nullable' : ($needsOptions ? 'required' : 'nullable'), 'array'],
            'correct.*' => ['integer'],
        ], [
            'correct.required' => 'Mark the correct answer.',
        ]);

        if ($needsOptions && empty($data['correct'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'correct' => 'Mark at least one correct option.',
            ]);
        }

        return $data;
    }

    /**
     * Options arrive as rows [{text}], with `correct[]` holding the indexes of
     * the right answers (one for single/true-false, many for multiple choice).
     */
    protected function syncOptions(Question $question, array $data, bool $replace = false): void
    {
        if ($replace) {
            $question->options()->delete();
        }

        if (! in_array($data['type'], ['single_choice', 'multiple_choice', 'true_false'], true)) {
            return;
        }

        $correct = collect($data['correct'] ?? [])->map(fn ($index) => (int) $index);

        foreach (array_values($data['options'] ?? []) as $index => $option) {
            $question->options()->create([
                'text' => $option['text'],
                'is_correct' => $correct->contains($index),
                'position' => $index + 1,
            ]);
        }
    }
}
