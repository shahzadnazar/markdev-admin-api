<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RestrictsToInstructor;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentAttachment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AssignmentController extends Controller
{
    use RestrictsToInstructor;

    public function index(Request $request): View
    {
        $assignments = Assignment::query()
            ->with(['course', 'lesson'])
            ->withCount(['submissions', 'submissions as ungraded_count' => fn ($query) => $query->whereNull('graded_at')])
            ->when(($mine = $this->managedCourseIds($request)) !== null, fn ($query) => $query->whereIn('course_id', $mine))
            ->when($request->filled('course'), fn ($query) => $query->where('course_id', $request->integer('course')))
            ->when($request->filled('search'), fn ($query) => $query->where('title', 'like', '%'.trim($request->string('search')).'%'))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('admin.assignments.index', [
            'assignments' => $assignments,
            'courses' => $this->selectableCourses($request)->get(['id', 'title']),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.assignments.form', [
            'assignment' => null,
            'courses' => $this->selectableCourses($request)->with('lessons:id,course_id,title')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->authorizeCourseAccess($request, (int) $data['course_id']);

        $assignment = Assignment::create(collect($data)->except('attachments')->all());
        $this->storeAttachments($request, $assignment);

        return redirect()->route('admin.assignments.index')->with('success', "Assignment \"{$assignment->title}\" created.");
    }

    public function edit(Request $request, Assignment $assignment): View
    {
        $this->authorizeCourseAccess($request, $assignment->course_id);

        return view('admin.assignments.form', [
            'assignment' => $assignment->load('attachments'),
            'courses' => $this->selectableCourses($request)->with('lessons:id,course_id,title')->get(),
        ]);
    }

    public function update(Request $request, Assignment $assignment): RedirectResponse
    {
        $this->authorizeCourseAccess($request, $assignment->course_id);
        $data = $this->validated($request);
        $this->authorizeCourseAccess($request, (int) $data['course_id']);

        $assignment->update(collect($data)->except('attachments')->all());
        $this->storeAttachments($request, $assignment);

        return redirect()->route('admin.assignments.index')->with('success', "Assignment \"{$assignment->title}\" updated.");
    }

    public function destroy(Request $request, Assignment $assignment): RedirectResponse
    {
        $this->authorizeCourseAccess($request, $assignment->course_id);
        $title = $assignment->title;
        $assignment->delete();

        return redirect()->route('admin.assignments.index')->with('success', "Assignment \"{$title}\" deleted.");
    }

    public function destroyAttachment(Request $request, Assignment $assignment, AssignmentAttachment $attachment): RedirectResponse
    {
        abort_unless($attachment->assignment_id === $assignment->id, 404);
        $this->authorizeCourseAccess($request, $assignment->course_id);

        if ($attachment->file_path) {
            Storage::disk('public')->delete($attachment->file_path);
        }
        $attachment->delete();

        return back()->with('success', 'Attachment removed.');
    }

    public function submissions(Request $request, Assignment $assignment): View
    {
        $this->authorizeCourseAccess($request, $assignment->course_id);
        $assignment->load('course');

        $submissions = $assignment->submissions()
            ->with(['user', 'grader'])
            ->orderByRaw('graded_at is not null')
            ->orderByDesc('submitted_at')
            ->paginate(15);

        return view('admin.assignments.submissions', [
            'assignment' => $assignment,
            'submissions' => $submissions,
        ]);
    }

    public function grade(Request $request, AssignmentSubmission $submission): RedirectResponse
    {
        $submission->load('assignment');
        $this->authorizeCourseAccess($request, $submission->assignment->course_id);

        $data = $request->validate([
            'score' => ['required', 'integer', 'min:0', 'max:'.($submission->assignment->max_score ?? 100)],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ]);

        $submission->update([
            'score' => $data['score'],
            'feedback' => $data['feedback'] ?? null,
            'graded_at' => now(),
            'graded_by' => $request->user()->id,
        ]);

        AuditLogger::log('graded', 'assignment_submissions', $submission->id, null, [
            'assignment' => $submission->assignment->title,
            'score' => $data['score'],
            'max_score' => $submission->assignment->max_score,
        ]);

        return back()->with('success', 'Submission graded.');
    }

    /**
     * Send a submission back to the student: feedback is required, any grade
     * is cleared, and the student can resubmit until it's graded again.
     */
    public function returnForChanges(Request $request, AssignmentSubmission $submission): RedirectResponse
    {
        $submission->load(['assignment', 'user']);
        $this->authorizeCourseAccess($request, $submission->assignment->course_id);

        $data = $request->validate([
            'feedback' => ['required', 'string', 'max:5000'],
        ]);

        $submission->update([
            'feedback' => $data['feedback'],
            'score' => null,
            'graded_at' => null,
            'graded_by' => $request->user()->id,
            'returned_at' => now(),
        ]);

        AuditLogger::log('returned', 'assignment_submissions', $submission->id, null, [
            'assignment' => $submission->assignment->title,
            'student' => $submission->user?->name,
        ]);

        return back()->with('success', 'Returned to '.($submission->user?->name ?? 'the student').' for changes.');
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'course_id' => ['required', Rule::exists('courses', 'id')],
            'lesson_id' => ['nullable', Rule::exists('lessons', 'id')->where('course_id', $request->integer('course_id'))],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'instructions' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
            'max_score' => ['required', 'integer', 'min:1', 'max:1000'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:20480'],
        ]);

        $data['lesson_id'] = $data['lesson_id'] ?? null;

        return $data;
    }

    protected function storeAttachments(Request $request, Assignment $assignment): void
    {
        foreach ($request->file('attachments', []) as $file) {
            $assignment->attachments()->create([
                'name' => $file->getClientOriginalName(),
                'file_path' => $file->store('attachments', 'public'),
                'file_type' => $file->getClientOriginalExtension() ?: $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
            ]);
        }
    }
}
