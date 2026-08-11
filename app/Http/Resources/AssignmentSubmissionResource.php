<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AssignmentSubmission */
class AssignmentSubmissionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'assignment_id' => $this->assignment_id,
            'content' => $this->content,
            'file_url' => $this->file_url,
            'file_name' => $this->file_name,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'is_late' => (bool) $this->is_late,
            'score' => $this->score !== null ? (int) $this->score : null,
            'feedback' => $this->feedback,
            'graded_at' => $this->graded_at?->toISOString(),
            'returned_at' => $this->returned_at?->toISOString(),
        ];
    }
}
