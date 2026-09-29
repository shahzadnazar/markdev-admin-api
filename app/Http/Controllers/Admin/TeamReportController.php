<?php

namespace App\Http\Controllers\Admin;

use App\Exports\TeamAttendanceExport;
use App\Exports\TeamFineLedgerExport;
use App\Exports\TeamMemberDeliveryExport;
use App\Exports\TeamProjectDeliveryExport;
use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Four reports about the team portal's work, as spreadsheets.
 *
 * ## AN EXPORT BYPASSES EVERY VIEW GATE
 *
 * This is the part of the phase to get right. A report is generated from a
 * QUERY, not from a screen, so none of the `@can` checks that protect the
 * dashboard apply to it — a lead who can open this page reaches the export
 * endpoint with nothing between them and a `select *` except what the export
 * class itself does. So every export takes the VIEWER and scopes from the same
 * scopes the screens use, and TeamReportTest asserts on the generated ROWS
 * rather than on the HTTP status, because the status is the one thing that
 * would be green either way.
 *
 * ## Excel, and no PDF
 *
 * A second renderer to keep working, for no information the first one does not
 * already carry. The four are Maatwebsite exports like the academy's five.
 *
 * ## Who may have what
 *
 * Keyed per report rather than gated at the route, the same shape as the
 * academy's ReportController — where only the transactions export names a
 * permission because it is the only one made of money. Here it is the fine
 * ledger, for the same reason and one more: a lead has never been able to see
 * what a colleague owes, and an export is exactly how that would stop being
 * true without anybody editing a screen.
 */
class TeamReportController extends Controller
{
    /**
     * @var array<string, array{label: string, description: string, class: class-string, permission: string|null, dated: bool}>
     */
    protected array $reports = [
        'member-delivery' => [
            'label' => 'Member delivery',
            'description' => 'Per member: stints finished in the month, how many were on time, days promised, days over and days blocked.',
            'class' => TeamMemberDeliveryExport::class,
            'permission' => null,
            'dated' => true,
        ],
        'project-delivery' => [
            'label' => 'Project delivery',
            'description' => 'Per project: days promised against days taken, and the variance between them. No client and no value.',
            'class' => TeamProjectDeliveryExport::class,
            'permission' => null,
            // Not dated: a project's promise and its spend are the whole of its
            // life, not a slice of one month.
            'dated' => false,
        ],
        'team-attendance' => [
            'label' => 'Team attendance',
            'description' => 'The register for the month, a row per marked day.',
            'class' => TeamAttendanceExport::class,
            'permission' => null,
            'dated' => true,
        ],
        'fine-ledger' => [
            'label' => 'Absence fine ledger',
            'description' => 'What each member owes for the month, and whether it is settled.',
            'class' => TeamFineLedgerExport::class,
            // ADMIN ONLY. A lead never sees a colleague's ledger — on a screen
            // or in a spreadsheet.
            'permission' => 'clients.view',
            'dated' => true,
        ],
    ];

    public function index(Request $request): View
    {
        return view('admin.team-reports.index', [
            'reports' => $this->reportsFor($request),
            'month' => $this->month($request),
        ]);
    }

    public function export(Request $request, string $report)
    {
        abort_unless(array_key_exists($report, $this->reports), 404);

        // 403 rather than 404: the report exists, it is simply not theirs.
        // Guessing the URL must not be a way around the listing.
        abort_unless(array_key_exists($report, $this->reportsFor($request)), 403);

        $month = $this->month($request);

        AuditLogger::log('exported', 'team-reports', null, null, [
            'report' => $report,
            'month' => $month->format('Y-m'),
            'format' => 'xlsx',
        ]);

        $export = $this->make($report, $request, $month);

        return Excel::download($export, sprintf('%s-%s.xlsx', $report, $month->format('Y-m')));
    }

    /**
     * Build one export for this viewer.
     *
     * The VIEWER is passed in, always. That is the whole authorisation story
     * for an export: there is no screen between the URL and the query, so the
     * query has to know who is asking.
     */
    protected function make(string $report, Request $request, Carbon $month): object
    {
        $class = $this->reports[$report]['class'];

        return $this->reports[$report]['dated']
            ? new $class($request->user(), $month)
            : new $class($request->user());
    }

    /** A bad or missing month is this month, as on the dashboard and the calendar. */
    protected function month(Request $request): Carbon
    {
        return rescue(
            fn () => Carbon::createFromFormat('Y-m', (string) $request->query('month'))->startOfMonth(),
            fn () => Carbon::today()->startOfMonth(),
            false,
        );
    }

    /**
     * The reports this viewer may have, by permission and never by role name.
     *
     * @return array<string, array<string, mixed>>
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
