<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One day of a student's attendance.
 *
 * Was AttendanceRecordResource, named for the per-class table that no longer
 * exists. The keys are unchanged — the portal reads them and the merge of the
 * two attendance tables is not the student's business.
 *
 * @mixin \App\Models\DailyAttendance
 */
class AttendanceDayResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date?->toDateString(),
            'status' => $this->status,
            'course' => $this->course ? new CourseRefResource($this->course) : null,
            'session_title' => $this->session_title,
            // The register calls this column `remarks`; the key the portal
            // reads is unchanged so the endpoint's shape survives the merge.
            'notes' => $this->remarks,
        ];
    }
}
