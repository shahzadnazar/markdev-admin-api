<?php

namespace App\Exports;

use App\Models\AttendanceRecord;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class AttendanceExport implements FromQuery, WithHeadings, WithMapping
{
    public function query(): Builder
    {
        return AttendanceRecord::query()
            ->with(['user', 'course', 'recorder'])
            ->orderByDesc('date');
    }

    public function headings(): array
    {
        return ['Date', 'Student', 'Email', 'Course', 'Session', 'Status', 'Notes', 'Recorded By'];
    }

    /** @param AttendanceRecord $record */
    public function map($record): array
    {
        return [
            $record->date?->toDateString(),
            $record->user?->name,
            $record->user?->email,
            $record->course?->title,
            $record->session_title,
            $record->status,
            $record->notes,
            $record->recorder?->name,
        ];
    }
}
