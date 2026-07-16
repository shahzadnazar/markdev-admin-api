<?php

namespace App\Http\Controllers\Admin;

use App\Exports\AttendanceExport;
use App\Exports\CourseCompletionExport;
use App\Exports\EnrollmentsExport;
use App\Exports\QuizResultsExport;
use App\Exports\TransactionsExport;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\QuizAttempt;
use App\Models\Transaction;
use App\Support\AuditLogger;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    /** @var array<string, array{label: string, description: string, class: class-string}> */
    protected array $reports = [
        'enrollments' => [
            'label' => 'Enrollments',
            'description' => 'Every enrollment with student, course, progress and completion.',
            'class' => EnrollmentsExport::class,
        ],
        'course-completion' => [
            'label' => 'Course completion',
            'description' => 'Per-course enrollment, completion counts and average progress.',
            'class' => CourseCompletionExport::class,
        ],
        'attendance' => [
            'label' => 'Attendance',
            'description' => 'Full attendance register with statuses and recorders.',
            'class' => AttendanceExport::class,
        ],
        'quiz-results' => [
            'label' => 'Quiz results',
            'description' => 'All quiz attempts with scores and pass / fail outcomes.',
            'class' => QuizResultsExport::class,
        ],
        'transactions' => [
            'label' => 'Transactions',
            'description' => 'Payment history with methods, amounts and statuses.',
            'class' => TransactionsExport::class,
        ],
    ];

    public function index(): View
    {
        $counts = [
            'enrollments' => Enrollment::count(),
            'course-completion' => Course::count(),
            'attendance' => AttendanceRecord::count(),
            'quiz-results' => QuizAttempt::count(),
            'transactions' => Transaction::count(),
        ];

        return view('admin.reports.index', [
            'reports' => $this->reports,
            'counts' => $counts,
        ]);
    }

    public function export(string $report)
    {
        abort_unless(array_key_exists($report, $this->reports), 404);

        AuditLogger::log('exported', 'reports', null, null, ['report' => $report, 'format' => 'xlsx']);

        $class = $this->reports[$report]['class'];

        return Excel::download(new $class, $report.'-'.now()->format('Y-m-d').'.xlsx');
    }
}
