<?php

namespace App\Exports;

use App\Models\Enrollment;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class EnrollmentsExport implements FromQuery, WithHeadings, WithMapping
{
    public function query(): Builder
    {
        return Enrollment::query()->with(['user', 'course'])->latest('enrolled_at');
    }

    public function headings(): array
    {
        return ['Student', 'Email', 'Course', 'Enrolled At', 'Progress %', 'Completed At', 'Last Activity'];
    }

    /** @param Enrollment $enrollment */
    public function map($enrollment): array
    {
        return [
            $enrollment->user?->name,
            $enrollment->user?->email,
            $enrollment->course?->title,
            $enrollment->enrolled_at?->toDateTimeString(),
            (float) $enrollment->progress_percent,
            $enrollment->completed_at?->toDateTimeString(),
            $enrollment->last_activity_at?->toDateTimeString(),
        ];
    }
}
