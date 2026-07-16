<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Assignment */
class AssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $submission = $this->relationLoaded('submissions') ? $this->submissions->first() : null;

        $status = match (true) {
            $submission?->graded_at !== null => 'graded',
            $submission !== null => 'submitted',
            $this->due_at !== null && $this->due_at->isPast() => 'overdue',
            default => 'pending',
        };

        return [
            'id' => $this->id,
            'course' => new CourseRefResource($this->course),
            'lesson_id' => $this->lesson_id,
            'title' => $this->title,
            'description' => $this->description,
            'instructions' => $this->instructions,
            'due_at' => $this->due_at?->toISOString(),
            'max_score' => (int) $this->max_score,
            'attachments' => ResourceFileResource::collection($this->attachments),
            'status' => $status,
            'submission' => $submission ? new AssignmentSubmissionResource($submission) : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
