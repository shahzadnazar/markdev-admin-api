<?php

namespace App\Exports;

use App\Models\DailyAttendance;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class AttendanceExport implements FromQuery, WithHeadings, WithMapping
{
    public function query(): Builder
    {
        return DailyAttendance::query()
            ->decided()
            ->with(['user', 'course', 'marker'])
            ->orderByDesc('date');
    }

    public function headings(): array
    {
        return ['Date', 'Student', 'Email', 'Course', 'Session', 'Status', 'Notes', 'Recorded By'];
    }

    /** @param DailyAttendance $record */
    public function map($record): array
    {
        return [
            $record->date?->toDateString(),
            $record->user?->name,
            $record->user?->email,
            $record->course?->title,
            $record->session_title,
            $record->status,
            $record->remarks,
            $record->marker?->name,
        ];
    }
}
