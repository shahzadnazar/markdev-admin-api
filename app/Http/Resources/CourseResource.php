<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Course */
class CourseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $enrollment = $this->relationLoaded('enrollments') ? $this->enrollments->first() : null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'description' => $this->description,
            'thumbnail_url' => $this->thumbnail_url,
            'level' => $this->level,
            'category' => $this->category ? new CategoryResource($this->category) : null,
            'instructor' => $this->instructor ? new InstructorSummaryResource($this->instructor) : null,
            'tags' => $this->tags ?? [],
            'duration_minutes' => (int) $this->duration_minutes,
            'duration_label' => $this->duration_label,
            'modules_count' => (int) ($this->modules_count ?? 0),
            'lessons_count' => (int) ($this->lessons_count ?? 0),
            'students_count' => (int) ($this->students_count ?? 0),
            'rating' => $this->rating !== null ? (float) $this->rating : null,
            'is_free' => (bool) $this->is_free,
            'price' => $this->price !== null ? (float) $this->price : null,
            'is_enrolled' => $enrollment !== null,
            'is_bookmarked' => (bool) ($this->is_bookmarked ?? false),
            'enrollment' => $enrollment ? new EnrollmentResource($enrollment) : null,
            'published_at' => $this->published_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
