<?php

namespace App\Exports;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class AuditLogsExport implements FromQuery, WithHeadings, WithMapping
{
    /** @param array<string, mixed> $filters */
    public function __construct(protected array $filters = [])
    {
    }

    public function query(): Builder
    {
        return static::filteredQuery($this->filters)->latest('created_at')->latest('id');
    }

    /**
     * Shared between the index screen and this export so the CSV always
     * honours the exact filters the admin is looking at.
     *
     * @param array<string, mixed> $filters
     */
    public static function filteredQuery(array $filters): Builder
    {
        return AuditLog::query()
            ->when(filled($filters['search'] ?? null), fn ($query) => $query->search((string) $filters['search']))
            ->when(filled($filters['user'] ?? null), fn ($query) => $query->where('user_id', (int) $filters['user']))
            ->when(filled($filters['action'] ?? null), fn ($query) => $query->where('action', (string) $filters['action']))
            ->when(filled($filters['module'] ?? null), fn ($query) => $query->where('module', (string) $filters['module']))
            ->when(filled($filters['from'] ?? null), fn ($query) => $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay()))
            ->when(filled($filters['to'] ?? null), fn ($query) => $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay()));
    }

    public function headings(): array
    {
        return [
            'ID', 'Timestamp', 'User', 'Role', 'Action', 'Module', 'Record ID',
            'Old Values', 'New Values', 'IP', 'Browser', 'OS', 'Device', 'URL', 'Method',
        ];
    }

    /** @param AuditLog $log */
    public function map($log): array
    {
        return [
            $log->id,
            $log->created_at?->toDateTimeString(),
            $log->user_name,
            $log->user_role,
            $log->action,
            $log->module,
            $log->record_id,
            $log->old_values ? json_encode($log->old_values) : null,
            $log->new_values ? json_encode($log->new_values) : null,
            $log->ip_address,
            $log->browser,
            $log->os,
            $log->device,
            $log->url,
            $log->http_method,
        ];
    }
}
