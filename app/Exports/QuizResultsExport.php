<?php

namespace App\Exports;

use App\Models\QuizAttempt;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class QuizResultsExport implements FromQuery, WithHeadings, WithMapping
{
    public function query(): Builder
    {
        return QuizAttempt::query()
            ->with(['quiz.course', 'user'])
            ->latest('started_at');
    }

    public function headings(): array
    {
        return ['Quiz', 'Course', 'Student', 'Email', 'Started', 'Submitted', 'Score', 'Max Score', 'Percent', 'Passed'];
    }

    /** @param QuizAttempt $attempt */
    public function map($attempt): array
    {
        return [
            $attempt->quiz?->title,
            $attempt->quiz?->course?->title,
            $attempt->user?->name,
            $attempt->user?->email,
            $attempt->started_at?->toDateTimeString(),
            $attempt->submitted_at?->toDateTimeString(),
            $attempt->score,
            $attempt->max_score,
            $attempt->max_score ? round(($attempt->score / $attempt->max_score) * 100, 1) : null,
            $attempt->passed === null ? null : ($attempt->passed ? 'Yes' : 'No'),
        ];
    }
}
