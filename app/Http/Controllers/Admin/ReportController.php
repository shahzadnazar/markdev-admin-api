<?php

namespace App\Http\Controllers\Admin;

use App\Exports\AttendanceExport;
use App\Exports\CourseCompletionExport;
use App\Exports\EnrollmentsExport;
use App\Exports\QuizResultsExport;
use App\Exports\TransactionsExport;
use App\Http\Controllers\Controller;
use App\Models\DailyAttendance;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\QuizAttempt;
use App\Models\Transaction;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    /**
     * The exports, and what each one needs beyond reports.view.
     *
     * `permission` is null for a report that reports.view alone covers. Only
     * the transactions export names one, because it is the only report made
     * of money: it carries student emails, amounts, payment methods and card
     * last-4 digits, and reports.export is held by roles that hold no billing
     * permission at all. Keyed per report rather than gated at the route, so
     * a report added later has to answer the question.
     *
     * @var array<string, array{label: string, description: string, class: class-string, permission: string|null}>
     */
    protected array $reports = [
        'enrollments' => [
            'label' => 'Enrollments',
            'description' => 'Every enrollment with student, course, progress and completion.',
            'class' => EnrollmentsExport::class,
            'permission' => null,
        ],
        'course-completion' => [
            'label' => 'Course completion',
            'description' => 'Per-course enrollment, completion counts and average progress.',
            'class' => CourseCompletionExport::class,
            'permission' => null,
        ],
        'attendance' => [
            'label' => 'Attendance',
            'description' => 'Full attendance register with statuses and recorders.',
            'class' => AttendanceExport::class,
            'permission' => null,
        ],
        'quiz-results' => [
            'label' => 'Quiz results',
            'description' => 'All quiz attempts with scores and pass / fail outcomes.',
            'class' => QuizResultsExport::class,
            'permission' => null,
        ],
        'transactions' => [
            'label' => 'Transactions',
            'description' => 'Payment history with methods, amounts and statuses.',
            'class' => TransactionsExport::class,
            'permission' => 'billing.view',
        ],
    ];

    public function index(Request $request): View
    {
        $reports = $this->reportsFor($request);

        // Counted per available report, so a viewer without billing.view does
        // not even run the transactions count. A row count is a small leak,
        // but it is still a number about money they may not see.
        $counts = collect([
            'enrollments' => fn () => Enrollment::count(),
            'course-completion' => fn () => Course::count(),
            'attendance' => fn () => DailyAttendance::decided()->count(),
            'quiz-results' => fn () => QuizAttempt::count(),
            'transactions' => fn () => Transaction::count(),
        ])->only(array_keys($reports))->map(fn (\Closure $count) => $count())->all();

        return view('admin.reports.index', [
            'reports' => $reports,
            'counts' => $counts,
        ]);
    }

    public function export(Request $request, string $report)
    {
        abort_unless(array_key_exists($report, $this->reports), 404);

        // 403 rather than 404: the report exists, it is simply not theirs.
        // Guessing the URL must not be a way around the listing.
        abort_unless(array_key_exists($report, $this->reportsFor($request)), 403);

        AuditLogger::log('exported', 'reports', null, null, ['report' => $report, 'format' => 'xlsx']);

        $class = $this->reports[$report]['class'];

        return Excel::download(new $class, $report.'-'.now()->format('Y-m-d').'.xlsx');
    }

    /**
     * The reports this viewer may have, by permission rather than role name.
     *
     * @return array<string, array{label: string, description: string, class: class-string, permission: string|null}>
     */
    protected function reportsFor(Request $request): array
    {
        $user = $request->user();

        return collect($this->reports)
            ->filter(fn (array $report) => $report['permission'] === null
                || (bool) $user?->can($report['permission']))
            ->all();
    }
}
