<?php

namespace App\Http\Controllers\Admin;

use App\Exports\AuditLogsExport;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

class AuditLogController extends Controller
{
    use \App\Http\Controllers\Admin\Concerns\FiltersByValues;

    public function index(Request $request): View
    {
        $logs = AuditLogsExport::filteredQuery($this->filters($request))
            ->latest('created_at')
            ->latest('id')
            ->paginate(20)
            ->appends(\Illuminate\Support\Arr::except($request->query(), ['partial', 'page']));

        // Live search re-renders only the results, so typing never reloads the
        // page and the cursor stays in the search box. The Filter button still
        // submits the form normally and lands here without `partial`.
        if ($request->boolean('partial')) {
            return view('admin.audit-logs._results', ['logs' => $logs]);
        }

        $filters = $this->filters($request);

        return view('admin.audit-logs.index', [
            'logs' => $logs,
            'users' => $this->loggedUsers(),
            'actions' => $this->loggedValues('action'),
            'modules' => $this->loggedValues('module'),
            'selected' => [
                'user' => $filters['user'],
                'action' => $filters['action'],
                'module' => $filters['module'],
            ],
        ]);
    }

    public function export(Request $request)
    {
        $filters = $this->filters($request);

        AuditLogger::log('exported', 'audit_logs', null, null, [
            'format' => 'csv',
            'filters' => array_filter($filters, fn ($value) => $value !== null && $value !== ''),
        ]);

        return Excel::download(
            new AuditLogsExport($filters),
            'audit-logs-'.now()->format('Y-m-d-Hi').'.csv',
            ExcelWriter::CSV,
        );
    }

    /**
     * The filters this request describes, shared with the CSV export so the
     * file always matches the screen it was taken from.
     *
     * user, action and module take several values now. Each is bounded by the
     * same list that fills its dropdown — the distinct values the log actually
     * holds — so nothing invented in the URL reaches the query.
     *
     * @return array<string, mixed>
     */
    protected function filters(Request $request): array
    {
        return $request->only(['search', 'from', 'to']) + [
            'user' => $this->filterIds($request, 'user', $this->loggedUsers()->pluck('id')->all()),
            'action' => $this->filterValues($request, 'action', $this->loggedValues('action')->all()),
            'module' => $this->filterValues($request, 'module', $this->loggedValues('module')->all()),
        ];
    }

    /** Everyone the log has an entry for — deleted accounts included. */
    protected function loggedUsers()
    {
        return User::withTrashed()
            ->whereIn('id', AuditLog::query()->select('user_id')->distinct())
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** The distinct values one log column actually holds. */
    protected function loggedValues(string $column)
    {
        return AuditLog::query()->select($column)->distinct()->orderBy($column)->pluck($column);
    }
}
