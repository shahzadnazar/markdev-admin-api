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
            // Arrays since the filters became multi-select. A scalar from an
            // older caller still works, and an empty array is no filter at all
            // rather than whereIn(..., []), which would match nothing.
            ->when(static::values($filters, 'user'), fn ($query, $ids) => $query->whereIn('user_id', $ids))
            ->when(static::values($filters, 'action'), fn ($query, $values) => $query->whereIn('action', $values))
            ->when(static::values($filters, 'module'), fn ($query, $values) => $query->whereIn('module', $values))
            ->when(filled($filters['from'] ?? null), fn ($query) => $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay()))
            ->when(filled($filters['to'] ?? null), fn ($query) => $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay()));
    }

    /**
     * One filter's values, however it arrived.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, mixed>
     */
    protected static function values(array $filters, string $key): array
    {
        $value = $filters[$key] ?? null;

        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        return is_array($value) ? array_values($value) : [$value];
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
