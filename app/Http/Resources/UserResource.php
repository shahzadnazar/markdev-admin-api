<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatar_url,
            'phone' => $this->phone,
            'batch_no' => $this->studentProfile?->batch_no,
            // Times go out already in 12-hour form; the portal shows them as given.
            'attendance_slot' => $this->slotPayload(),
            'emergency_contact' => $this->studentProfile?->emergency_contact,
            'enrollment' => $this->enrollmentPayload(),
            'headline' => $this->headline,
            'roles' => $this->getRoleNames()->values(),
            'permissions' => $this->getAllPermissions()->pluck('name')->values(),
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * The course the student is currently taking, and when they joined it.
     *
     * "Currently" means the newest enrolment they have not finished; once every
     * course is complete it falls back to the newest overall, so the profile
     * still names something rather than going blank. This resource only ever
     * serialises the authenticated user, so the lookup is one row, not an N+1.
     *
     * @return array<string, mixed>|null
     */
    protected function enrollmentPayload(): ?array
    {
        $enrollment = $this->enrollments()
            ->with('course:id,title')
            ->orderByRaw('completed_at is null desc')
            ->orderByDesc('enrolled_at')
            ->first();

        if ($enrollment === null) {
            return null;
        }

        return [
            'course' => $enrollment->course === null ? null : [
                'id' => $enrollment->course->id,
                'title' => $enrollment->course->title,
            ],
            'enrolled_at' => $enrollment->enrolled_at?->toISOString(),
        ];
    }

    /**
     * The student's attendance slot, already formatted for display.
     *
     * @return array<string, mixed>|null
     */
    protected function slotPayload(): ?array
    {
        $slot = $this->studentProfile?->attendanceSlot;

        if ($slot === null) {
            return null;
        }

        return [
            'id' => $slot->id,
            'name' => $slot->name,
            'start_time' => $slot->startLabel(),
            'end_time' => $slot->endLabel(),
        ];
    }
}
