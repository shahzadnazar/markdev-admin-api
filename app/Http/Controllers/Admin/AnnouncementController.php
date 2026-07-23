<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RestrictsToInstructor;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Course;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    use RestrictsToInstructor;

    public function index(Request $request): View
    {
        $announcements = Announcement::query()
            ->with(['author', 'course'])
            ->withCount('reads')
            ->when(($mine = $this->managedCourseIds($request)) !== null, fn ($query) => $query->whereIn('course_id', $mine))
            ->when($request->filled('search'), fn ($query) => $query->where('title', 'like', '%'.trim($request->string('search')).'%'))
            ->when($request->filled('course'), fn ($query) => $query->where('course_id', $request->integer('course')))
            ->orderByDesc('is_pinned')
            ->latest('published_at')
            ->paginate(10)
            ->withQueryString();

        return view('admin.announcements.index', [
            'announcements' => $announcements,
            'courses' => $this->selectableCourses($request)->get(['id', 'title']),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.announcements.form', [
            'announcement' => null,
            'courses' => $this->selectableCourses($request)->get(['id', 'title']),
            'requireCourse' => $this->managedCourseIds($request) !== null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        Announcement::create([...$data, 'author_id' => $request->user()->id]);

        return redirect()->route('admin.announcements.index')->with('success', 'Announcement published.');
    }

    public function edit(Request $request, Announcement $announcement): View
    {
        $this->authorizeCourseAccess($request, $announcement->course_id);

        return view('admin.announcements.form', [
            'announcement' => $announcement,
            'courses' => $this->selectableCourses($request)->get(['id', 'title']),
            'requireCourse' => $this->managedCourseIds($request) !== null,
        ]);
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        $this->authorizeCourseAccess($request, $announcement->course_id);
        $announcement->update($this->validated($request));

        return redirect()->route('admin.announcements.index')->with('success', 'Announcement updated.');
    }

    public function destroy(Request $request, Announcement $announcement): RedirectResponse
    {
        $this->authorizeCourseAccess($request, $announcement->course_id);
        $announcement->delete();

        return redirect()->route('admin.announcements.index')->with('success', 'Announcement deleted.');
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'course_id' => ['nullable', Rule::exists('courses', 'id')],
            'is_pinned' => ['nullable', 'boolean'],
            'published_at' => ['nullable', 'date'],
        ]);

        $data['course_id'] = $data['course_id'] ?? null;

        // Instructors always post to one of their own courses — never globally.
        if ($this->managedCourseIds($request) !== null) {
            if ($data['course_id'] === null) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'course_id' => 'Select one of your courses — instructors cannot post academy-wide announcements.',
                ]);
            }
            $this->authorizeCourseAccess($request, (int) $data['course_id']);
        }

        $data['is_pinned'] = $request->boolean('is_pinned');
        $data['published_at'] = $data['published_at'] ?? now();

        return $data;
    }
}
