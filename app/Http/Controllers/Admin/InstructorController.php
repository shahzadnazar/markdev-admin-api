<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssignmentSubmission;
use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Faculty management — the instructor directory and per-instructor profile. */
class InstructorController extends Controller
{
    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), ['active', 'inactive'], true)
            ? $request->query('status')
            : null;

        $instructors = User::role('instructor')
            ->withCount(['taughtCourses'])
            ->with(['taughtCourses' => fn ($query) => $query->select('id', 'instructor_id', 'title')->orderBy('title')])
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.trim($request->string('search')).'%';
                $query->where(fn ($inner) => $inner->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('headline', 'like', $term));
            })
            ->when($status !== null, fn ($query) => $query->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        $totals = [
            'faculty' => User::role('instructor')->count(),
            'active' => User::role('instructor')->where('is_active', true)->count(),
            'courses' => Course::whereNotNull('instructor_id')->count(),
            'students' => Enrollment::whereIn(
                'course_id',
                Course::whereNotNull('instructor_id')->select('id'),
            )->distinct('user_id')->count('user_id'),
        ];

        return view('admin.instructors.index', [
            'instructors' => $instructors,
            'totals' => $totals,
            'status' => $status,
        ]);
    }

    public function show(User $instructor): View
    {
        abort_unless($instructor->hasRole('instructor'), 404);

        $courses = Course::where('instructor_id', $instructor->id)
            ->withCount(['enrollments', 'lessons'])
            ->orderBy('title')
            ->get();

        $courseIds = $courses->pluck('id');

        $pendingGrading = AssignmentSubmission::whereNull('graded_at')
            ->whereHas('assignment', fn ($query) => $query->whereIn('course_id', $courseIds))
            ->count();

        $schedule = CalendarEvent::whereIn('course_id', $courseIds)
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->limit(8)
            ->with('course:id,title')
            ->get();

        return view('admin.instructors.show', [
            'instructor' => $instructor,
            'courses' => $courses,
            'students' => Enrollment::whereIn('course_id', $courseIds)->distinct('user_id')->count('user_id'),
            'pendingGrading' => $pendingGrading,
            'schedule' => $schedule,
        ]);
    }
}
