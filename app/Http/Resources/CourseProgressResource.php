<?php

namespace App\Http\Resources;

use App\Services\CourseProgressCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The portal `CourseProgress` shape, built from an Enrollment whose
 * `completed_lessons`, `total_lessons` and `time_spent_minutes` attributes
 * were pre-computed (see LearningStatsService) and whose course is loaded.
 *
 * @mixin \App\Models\Enrollment
 */
class CourseProgressResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $breakdown = app(CourseProgressCalculator::class)->breakdown(
            $request->user(),
            $this->course,
        );

        return [
            'course' => new CourseRefResource($this->course),
            /*
             * COMPUTED, not read from enrollments.progress_percent.
             *
             * That column is a cache for admin list screens. This is a page
             * about one student, so it computes from the raw records and is
             * never a save behind — including after an instructor marks the
             * register, which the student did not do and cannot trigger.
             */
            'progress_percent' => $breakdown['total'],
            'coursework_percent' => $breakdown['coursework_total'],
            // Never one opaque number: the portal shows what each component
            // scored, what it was worth, and what it contributed.
            'breakdown' => $breakdown['components'],
            'completed_lessons' => (int) ($this->completed_lessons ?? 0),
            'total_lessons' => (int) ($this->total_lessons ?? 0),
            'time_spent_minutes' => (int) ($this->time_spent_minutes ?? 0),
            'last_activity_at' => $this->last_activity_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
        ];
    }
}
