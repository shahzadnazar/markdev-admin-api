<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RestrictsToInstructor;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\FeePlan;
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

    /** Invoice states that mean a fee plan is still being collected. */
    private const UNPAID_INVOICE_STATUSES = ['upcoming', 'open', 'pending', 'past_due'];

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

        $plans = FeePlan::query()
            ->whereIn('user_id', $enrollments->pluck('user_id')->filter())
            ->whereIn('course_id', $enrollments->pluck('course_id')->filter())
            ->withCount([
                'invoices as total_invoices',
                'invoices as paid_invoices' => fn ($query) => $query->where('status', 'paid'),
            ])
            ->get()
            ->keyBy(fn ($plan) => $plan->user_id.'-'.$plan->course_id);

        return view('admin.enrollments.index', [
            'enrollments' => $enrollments,
            'plans' => $plans,
            'courses' => $this->selectableCourses($request)->get(['id', 'title']),
        ]);
    }

    /** Student picker: every active student, filterable, with Enroll now. */
    public function create(Request $request): View
    {
        $courseId = $request->filled('course') ? $request->integer('course') : null;
        $tab = $request->query('tab') === 'unenrolled' ? 'unenrolled' : 'all';
        $autoOpen = $request->integer('enroll') ?: null;

        $students = User::role('student')
            ->where('is_active', true)
            ->with([
                'studentProfile:id,user_id,reg_no,cnic',
                'enrollments.course:id,title',
                'feePlans' => fn ($query) => $query
                    ->select('id', 'user_id', 'course_id')
                    ->where('is_active', true)
                    ->whereHas('invoices', fn ($inner) => $inner->whereIn('status', self::UNPAID_INVOICE_STATUSES)),
            ])
            ->when($autoOpen, fn ($query) => $query->where('id', $autoOpen))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.trim($request->string('search')).'%';
                $query->where(fn ($inner) => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhereHas('studentProfile', fn ($profile) => $profile
                        ->where('reg_no', 'like', $term)
                        ->orWhere('cnic', 'like', $term)));
            })
            ->when($courseId, fn ($query) => $query
                ->whereHas('enrollments', fn ($inner) => $inner->where('course_id', $courseId)))
            ->when($tab === 'unenrolled', fn ($query) => $query->whereDoesntHave('enrollments'))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.enrollments.create', [
            'students' => $students,
            'courses' => Course::orderBy('title')->get(['id', 'title']),
            'defaultFinePerDay' => BillingConfig::finePerDay(),
            'courseId' => $courseId,
            'tab' => $tab,
            'autoOpen' => $autoOpen,
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

        $student = User::findOrFail($data['user_id']);
        abort_unless($student->hasRole('student') && $student->is_active, 422, 'Only active students can be enrolled.');

        // The unique index spans soft-deleted rows, so look those up too:
        // a removed enrollment is restored instead of colliding on insert.
        $existing = Enrollment::withTrashed()
            ->where('user_id', $data['user_id'])
            ->where('course_id', $data['course_id'])
            ->first();
        $alreadyEnrolled = $existing && ! $existing->trashed();

        // Already enrolled with no fee requested → nothing to do.
        if ($alreadyEnrolled && ! $withPlan) {
            return back()->with('error', "{$student->name} is already enrolled in that course.");
        }

        // One in-flight plan per student & course — completed plans don't block.
        if ($withPlan) {
            $inFlight = FeePlan::where('user_id', $data['user_id'])
                ->where('course_id', $data['course_id'])
                ->where('is_active', true)
                ->whereHas('invoices', fn ($query) => $query->whereIn('status', self::UNPAID_INVOICE_STATUSES))
                ->exists();

            if ($inFlight) {
                return back()->with('error', "{$student->name} already has an active fee plan for that course — manage it under Finance → Fee plans.");
            }
        }

        if (! $alreadyEnrolled) {
            if ($existing) {
                $existing->restore();
                $existing->update([
                    'enrolled_at' => now(),
                    'progress_percent' => 0,
                    'completed_at' => null,
                ]);
            } else {
                Enrollment::create([
                    'user_id' => $data['user_id'],
                    'course_id' => $data['course_id'],
                    'enrolled_at' => now(),
                    'progress_percent' => 0,
                ]);
            }
        }

        if ($withPlan) {
            $course = Course::findOrFail($data['course_id']);
            $months = (int) $data['months'];

            $installments->create(
                student: $student,
                course: $course,
                title: $course->title,
                totalFee: (float) $data['total_fee'],
                months: $months,
                dueDay: (int) $data['due_day'],
                finePerDay: $request->filled('fine_per_day') ? (float) $data['fine_per_day'] : null,
                currency: strtoupper($data['currency'] ?? 'PKR'),
            );

            $schedule = $months === 1
                ? 'full fee invoice created'
                : "{$months} monthly installments created (due day {$data['due_day']})";

            return redirect()->route('admin.enrollments.create')->with(
                'success',
                $alreadyEnrolled
                    ? "Fee generated for {$student->name} — {$schedule}."
                    : "{$student->name} enrolled — {$schedule}."
            );
        }

        return redirect()->route('admin.enrollments.create')->with('success', "{$student->name} enrolled.");
    }

    public function destroy(Enrollment $enrollment): RedirectResponse
    {
        $enrollment->delete();

        return redirect()->route('admin.enrollments.index')->with('success', 'Enrollment removed.');
    }
}
