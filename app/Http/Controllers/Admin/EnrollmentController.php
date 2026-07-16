<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EnrollmentController extends Controller
{
    public function index(Request $request): View
    {
        $enrollments = Enrollment::query()
            ->with(['user', 'course'])
            ->when($request->filled('course'), fn ($query) => $query->where('course_id', $request->integer('course')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.trim($request->string('search')).'%';
                $query->whereHas('user', fn ($inner) => $inner->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->latest('enrolled_at')
            ->paginate(12)
            ->withQueryString();

        return view('admin.enrollments.index', [
            'enrollments' => $enrollments,
            'courses' => Course::orderBy('title')->get(['id', 'title']),
        ]);
    }

    public function create(): View
    {
        return view('admin.enrollments.create', [
            'students' => User::role('student')->orderBy('name')->get(['id', 'name', 'email']),
            'courses' => Course::orderBy('title')->get(['id', 'title']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')],
            'course_id' => ['required', Rule::exists('courses', 'id')],
        ]);

        $exists = Enrollment::where('user_id', $data['user_id'])
            ->where('course_id', $data['course_id'])
            ->exists();

        if ($exists) {
            return back()->withInput()->withErrors(['user_id' => 'This student is already enrolled in that course.']);
        }

        Enrollment::create([
            ...$data,
            'enrolled_at' => now(),
            'progress_percent' => 0,
        ]);

        return redirect()->route('admin.enrollments.index')->with('success', 'Student enrolled.');
    }

    public function destroy(Enrollment $enrollment): RedirectResponse
    {
        $enrollment->delete();

        return redirect()->route('admin.enrollments.index')->with('success', 'Enrollment removed.');
    }
}
