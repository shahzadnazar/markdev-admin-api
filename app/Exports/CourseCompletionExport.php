<?php

namespace App\Exports;

use App\Models\Course;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CourseCompletionExport implements FromCollection, WithHeadings
{
    public function collection(): Collection
    {
        return Course::query()
            ->with('instructor:id,name')
            ->withCount([
                'enrollments',
                'enrollments as completed_count' => fn ($query) => $query->whereNotNull('completed_at'),
            ])
            ->withAvg('enrollments as avg_progress', 'progress_percent')
            ->orderBy('title')
            ->get()
            ->map(fn (Course $course) => [
                'course' => $course->title,
                'instructor' => $course->instructor?->name,
                'status' => $course->status,
                'enrollments' => (int) $course->enrollments_count,
                'completed' => (int) $course->completed_count,
                'completion_rate' => $course->enrollments_count > 0
                    ? round(($course->completed_count / $course->enrollments_count) * 100, 1)
                    : 0,
                'avg_progress' => round((float) $course->avg_progress, 1),
            ]);
    }

    public function headings(): array
    {
        return ['Course', 'Instructor', 'Status', 'Enrollments', 'Completed', 'Completion Rate %', 'Avg Progress %'];
    }
}
