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
    public function index(Request $request): View
    {
        $logs = AuditLogsExport::filteredQuery($this->filters($request))
            ->latest('created_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.audit-logs.index', [
            'logs' => $logs,
            'users' => User::withTrashed()->whereIn('id', AuditLog::query()->select('user_id')->distinct())->orderBy('name')->get(['id', 'name']),
            'actions' => AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action'),
            'modules' => AuditLog::query()->select('module')->distinct()->orderBy('module')->pluck('module'),
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

    /** @return array<string, mixed> */
    protected function filters(Request $request): array
    {
        return $request->only(['search', 'user', 'action', 'module', 'from', 'to']);
    }
}
