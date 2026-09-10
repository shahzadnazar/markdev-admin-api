<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RestrictsToInstructor;
use App\Http\Controllers\Admin\Concerns\FiltersByValues;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Course;
use App\Models\User;
use App\Notifications\AnnouncementPublished;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    use FiltersByValues, RestrictsToInstructor;

    public function index(Request $request): View
    {
        // The options are the allowed values, and selectableCourses() is
        // already narrowed to an instructor's own courses — so a course they
        // cannot see drops out of the filter rather than widening the list.
        $courses = $this->selectableCourses($request)->get(['id', 'title']);
        $courseIds = $this->filterIds($request, 'course', $courses->pluck('id')->all());

        $announcements = Announcement::query()
            ->with(['author', 'course'])
            ->withCount('reads')
            ->when(($mine = $this->managedCourseIds($request)) !== null, fn ($query) => $query->whereIn('course_id', $mine))
            ->when($request->filled('search'), fn ($query) => $query->where('title', 'like', '%'.trim($request->string('search')).'%'))
            ->when($courseIds, fn ($query) => $query->whereIn('course_id', $courseIds))
            ->orderByDesc('is_pinned')
            ->latest('published_at')
            ->paginate(10)
            ->appends(\Illuminate\Support\Arr::except($request->query(), ['partial', 'page']));

        // Live search re-renders only the results, so typing never reloads the
        // page and the cursor stays in the search box. The Filter button still
        // submits the form normally and lands here without `partial`.
        if ($request->boolean('partial')) {
            return view('admin.announcements._results', ['announcements' => $announcements]);
        }

        return view('admin.announcements.index', [
            'announcements' => $announcements,
            'courses' => $courses,
            'selected' => ['course' => $courseIds],
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

        $announcement = Announcement::create([...$data, 'author_id' => $request->user()->id]);
        $this->notifyAudience($announcement);

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
        $this->notifyAudience($announcement->refresh());

        return redirect()->route('admin.announcements.index')->with('success', 'Announcement updated.');
    }

    public function destroy(Request $request, Announcement $announcement): RedirectResponse
    {
        $this->authorizeCourseAccess($request, $announcement->course_id);
        $announcement->delete();

        return redirect()->route('admin.announcements.index')->with('success', 'Announcement deleted.');
    }

    /** @return array<string, mixed> */
    /**
     * Rings the bell for everyone an announcement is addressed to, exactly once
     * per announcement, and only after it's actually live. A future-dated
     * announcement is picked up here when it's next edited after going live.
     *
     * A course announcement reaches that course's enrolled students, as it
     * always has. An academy-wide one now reaches instructors as well: they
     * already see these in the staff ticker (3764cf2), the admin panel has the
     * same bell the portal does, and a closure or a fee deadline is not news
     * only students need. An instructor's own course announcements are still
     * students-only — they do not need a bell for a notice about their own
     * class — and nobody is ever notified of their own post.
     */
    protected function notifyAudience(Announcement $announcement): void
    {
        if ($announcement->notified_at !== null
            || $announcement->published_at === null
            || $announcement->published_at->isFuture()) {
            return;
        }

        $announcement->loadMissing('course');

        $roles = $announcement->course_id === null ? ['student', 'instructor'] : ['student'];

        User::role($roles)
            ->where('is_active', true)
            ->where('id', '!=', $announcement->author_id)
            ->when($announcement->course_id, fn ($query) => $query->whereHas(
                'enrollments',
                fn ($enrollment) => $enrollment->where('course_id', $announcement->course_id),
            ))
            ->chunkById(500, function ($recipients) use ($announcement) {
                foreach ($recipients as $recipient) {
                    $recipient->notify(new AnnouncementPublished($announcement));
                }
            });

        $announcement->forceFill(['notified_at' => now()])->save();
    }

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
