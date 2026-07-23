<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RestrictsToInstructor;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Course;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    use RestrictsToInstructor;

    /** Attendance sheet: enrolled students of a course on a given date. */
    public function index(Request $request): View
    {
        $request->validate([
            'course' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
        ]);

        $courses = $this->selectableCourses($request)->get(['id', 'title']);
        $course = $request->filled('course') ? Course::find($request->integer('course')) : null;
        if ($course) {
            $this->authorizeCourseAccess($request, $course->id);
        }
        $date = $request->filled('date') ? $request->date('date') : today();

        $students = collect();
        $existing = collect();

        if ($course) {
            $students = $course->enrollments()->with('user')->get()
                ->pluck('user')
                ->filter()
                ->sortBy('name')
                ->values();

            $existing = AttendanceRecord::where('course_id', $course->id)
                ->whereDate('date', $date)
                ->get()
                ->keyBy('user_id');
        }

        return view('admin.attendance.index', [
            'courses' => $courses,
            'course' => $course,
            'date' => $date,
            'students' => $students,
            'existing' => $existing,
        ]);
    }

    /** Bulk upsert of the sheet. */
    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'course_id' => ['required', Rule::exists('courses', 'id')],
            'date' => ['required', 'date'],
            'session_title' => ['nullable', 'string', 'max:255'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.user_id' => ['required', Rule::exists('users', 'id')],
            'rows.*.status' => ['nullable', Rule::in(['present', 'late', 'absent', 'excused'])],
            'rows.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->authorizeCourseAccess($request, (int) $data['course_id']);

        $saved = 0;

        foreach ($data['rows'] as $row) {
            if (empty($row['status'])) {
                continue; // untouched student rows are skipped
            }

            AttendanceRecord::updateOrCreate(
                [
                    'user_id' => $row['user_id'],
                    'course_id' => $data['course_id'],
                    'date' => $data['date'],
                ],
                [
                    'status' => $row['status'],
                    'notes' => $row['notes'] ?? null,
                    'session_title' => $data['session_title'] ?? null,
                    'recorded_by' => $request->user()->id,
                ],
            );
            $saved++;
        }

        AuditLogger::log('attendance_marked', 'attendance_records', null, null, [
            'course_id' => (int) $data['course_id'],
            'date' => $data['date'],
            'records' => $saved,
        ]);

        return redirect()
            ->route('admin.attendance.index', ['course' => $data['course_id'], 'date' => $data['date']])
            ->with('success', "Attendance saved for {$saved} student(s).");
    }

    /** Recent records, paginated. */
    public function log(Request $request): View
    {
        $records = AttendanceRecord::query()
            ->with(['user', 'course', 'recorder'])
            ->when(($mine = $this->managedCourseIds($request)) !== null, fn ($query) => $query->whereIn('course_id', $mine))
            ->when($request->filled('course'), fn ($query) => $query->where('course_id', $request->integer('course')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderByDesc('date')
            ->orderBy('user_id')
            ->paginate(15)
            ->withQueryString();

        return view('admin.attendance.log', [
            'records' => $records,
            'courses' => $this->selectableCourses($request)->get(['id', 'title']),
        ]);
    }
}
