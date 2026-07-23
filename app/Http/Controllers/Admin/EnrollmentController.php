<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RestrictsToInstructor;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\InstallmentPlanService;
use App\Support\BillingConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EnrollmentController extends Controller
{
    use RestrictsToInstructor;

    public function index(Request $request): View
    {
        $enrollments = Enrollment::query()
            ->with(['user', 'course'])
            ->when(($mine = $this->managedCourseIds($request)) !== null, fn ($query) => $query->whereIn('course_id', $mine))
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
            'courses' => $this->selectableCourses($request)->get(['id', 'title']),
        ]);
    }

    public function create(): View
    {
        return view('admin.enrollments.create', [
            'students' => User::role('student')->orderBy('name')->get(['id', 'name', 'email']),
            'courses' => Course::orderBy('title')->get(['id', 'title']),
            'defaultFinePerDay' => BillingConfig::finePerDay(),
        ]);
    }

    public function store(Request $request, InstallmentPlanService $installments): RedirectResponse
    {
        $withPlan = $request->boolean('create_plan');

        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')],
            'course_id' => ['required', Rule::exists('courses', 'id')],
            'total_fee' => [$withPlan ? 'required' : 'nullable', 'numeric', 'min:1', 'max:99999999'],
            'months' => [$withPlan ? 'required' : 'nullable', 'integer', 'min:1', 'max:36'],
            'due_day' => [$withPlan ? 'required' : 'nullable', 'integer', 'min:1', 'max:28'],
            'fine_per_day' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'currency' => [$withPlan ? 'required' : 'nullable', 'string', 'size:3'],
        ]);

        $exists = Enrollment::where('user_id', $data['user_id'])
            ->where('course_id', $data['course_id'])
            ->exists();

        if ($exists) {
            return back()->withInput()->withErrors(['user_id' => 'This student is already enrolled in that course.']);
        }

        Enrollment::create([
            'user_id' => $data['user_id'],
            'course_id' => $data['course_id'],
            'enrolled_at' => now(),
            'progress_percent' => 0,
        ]);

        if ($withPlan) {
            $student = User::findOrFail($data['user_id']);
            $course = Course::findOrFail($data['course_id']);

            $installments->create(
                student: $student,
                course: $course,
                title: $course->title,
                totalFee: (float) $data['total_fee'],
                months: (int) $data['months'],
                dueDay: (int) $data['due_day'],
                finePerDay: $request->filled('fine_per_day') ? (float) $data['fine_per_day'] : null,
                currency: strtoupper($data['currency'] ?? 'PKR'),
            );

            return redirect()->route('admin.enrollments.index')->with(
                'success',
                "Student enrolled — {$data['months']} monthly installments created (due day {$data['due_day']})."
            );
        }

        return redirect()->route('admin.enrollments.index')->with('success', 'Student enrolled.');
    }

    public function destroy(Enrollment $enrollment): RedirectResponse
    {
        $enrollment->delete();

        return redirect()->route('admin.enrollments.index')->with('success', 'Enrollment removed.');
    }
}
