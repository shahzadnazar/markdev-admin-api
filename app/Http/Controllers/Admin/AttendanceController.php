<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RestrictsToInstructor;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Course;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                ->onDate($date)
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

    /**
     * Bulk upsert of the sheet.
     *
     * Rows that turn a recorded absence into something else are the whole
     * reason this is not a plain loop over updateOrCreate. They are found
     * first, before anything is written, so the request either applies whole
     * or is refused whole: an instructor is told the absence is final instead
     * of being told the sheet saved, and an admin correcting one is made to
     * type why. AttendanceRecord::updating is the wall under this — it throws
     * on an unpermitted undo whatever calls it — and this is the door that
     * gives a person a sentence they can act on.
     */
    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'course_id' => ['required', Rule::exists('courses', 'id')],
            'date' => ['required', 'date'],
            'session_title' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'min:3', 'max:500'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.user_id' => ['required', Rule::exists('users', 'id')],
            'rows.*.status' => ['nullable', Rule::in(AttendanceRecord::STATUSES)],
            'rows.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->authorizeCourseAccess($request, (int) $data['course_id']);

        // Keyed by student, and read through onDate() rather than a plain
        // equality on a date-cast column — the same half-open range the
        // register uses. With an equality this lookup silently misses on any
        // store that keeps the time part, and every save appends a second row
        // for the same day instead of correcting the first.
        $existing = AttendanceRecord::where('course_id', $data['course_id'])
            ->onDate($data['date'])
            ->get()
            ->keyBy('user_id');

        $rows = collect($data['rows'])->filter(fn (array $row) => ! empty($row['status']));

        $corrections = $rows->filter(function (array $row) use ($existing) {
            $record = $existing->get((int) $row['user_id']);

            return $record !== null && $record->status === 'absent' && $row['status'] !== 'absent';
        });

        if ($corrections->isNotEmpty()) {
            abort_unless(
                AttendanceRecord::mayUndoAbsence(),
                403,
                'Absent is final. Ask an admin to correct it.',
            );

            if (blank($data['reason'] ?? null)) {
                return back()
                    ->withInput()
                    ->with('error', 'Changing a recorded absence needs a written reason — say why before saving.');
            }
        }

        $saved = 0;

        DB::transaction(function () use ($data, $rows, $existing, $corrections, $request, &$saved) {
            $correcting = $corrections->keyBy(fn (array $row) => (int) $row['user_id']);

            foreach ($rows as $row) {
                $userId = (int) $row['user_id'];
                $record = $existing->get($userId) ?? new AttendanceRecord([
                    'user_id' => $userId,
                    'course_id' => $data['course_id'],
                    'date' => $data['date'],
                ]);

                $wasCorrection = $correcting->has($userId);
                $old = $record->exists ? ['status' => $record->status, 'notes' => $record->notes] : null;

                $record->fill([
                    'status' => $row['status'],
                    'notes' => $row['notes'] ?? null,
                    'session_title' => $data['session_title'] ?? null,
                    'recorded_by' => $request->user()->id,
                ]);

                if ($wasCorrection) {
                    $record->fill([
                        'last_updated_by' => $request->user()->id,
                        'last_update_reason' => $data['reason'] ?? null,
                        'last_updated_at' => now(),
                    ]);
                }

                $record->save();
                $saved++;

                if ($wasCorrection) {
                    AuditLogger::log('attendance_corrected', 'attendance_records', $record->id, $old, [
                        'student' => $record->user?->name,
                        'course_id' => (int) $data['course_id'],
                        'date' => $data['date'],
                        'status' => $row['status'],
                        'reason' => $data['reason'] ?? null,
                    ]);
                }
            }
        });

        AuditLogger::log('attendance_marked', 'attendance_records', null, null, [
            'course_id' => (int) $data['course_id'],
            'date' => $data['date'],
            'records' => $saved,
            'corrected_absences' => $corrections->count(),
        ]);

        $corrected = $corrections->isEmpty()
            ? ''
            : " {$corrections->count()} recorded absence(s) corrected — the reason is on the record.";

        return redirect()
            ->route('admin.attendance.index', ['course' => $data['course_id'], 'date' => $data['date']])
            ->with('success', "Attendance saved for {$saved} student(s).".$corrected);
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
