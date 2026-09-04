<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LeaveApplication */
class LeaveApplicationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_date' => $this->from_date?->toDateString(),
            'to_date' => $this->to_date?->toDateString(),
            'days_count' => $this->from_date && $this->to_date
                ? (int) $this->from_date->diffInDays($this->to_date) + 1
                : 0,
            'reason' => $this->reason,
            'status' => $this->status,
            'review_note' => $this->review_note,
            // Per day, because a range can be partly approved and the student
            // is owed which days went which way rather than one verdict.
            'days' => $this->decisions
                ->sortBy('date')
                ->map(fn ($day) => [
                    'date' => $day->date->toDateString(),
                    'status' => $day->status,
                ])
                ->values()
                ->all(),
            'approved_days' => $this->decisions->where('status', 'approved')->count(),
            'declined_days' => $this->decisions->where('status', 'declined')->count(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
