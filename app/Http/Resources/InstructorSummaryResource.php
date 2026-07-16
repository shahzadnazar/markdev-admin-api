<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class InstructorSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'avatar_url' => $this->avatar_url,
            'headline' => $this->headline,
            'bio' => $this->whenHas('include_detail', fn () => $this->bio),
            'courses_count' => $this->whenHas('courses_count', fn () => (int) $this->courses_count),
            'students_count' => $this->whenHas('students_count', fn () => (int) $this->students_count),
        ];
    }
}
