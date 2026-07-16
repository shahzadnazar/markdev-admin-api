<?php

namespace App\Http\Resources;

use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Bookmark */
class BookmarkResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $target = $this->bookmarkable;
        $isCourse = $this->bookmarkable_type === Course::class;

        if ($isCourse) {
            /** @var Course|null $target */
            $title = $target?->title;
            $subtitle = $target?->category?->name;
            $thumbnail = $target?->thumbnail_url;
            $courseId = $this->bookmarkable_id;
        } else {
            /** @var Lesson|null $target */
            $title = $target?->title;
            $subtitle = $target?->course?->title;
            $thumbnail = $target?->course?->thumbnail_url;
            $courseId = $target?->course_id ?? 0;
        }

        return [
            'id' => $this->id,
            'type' => $isCourse ? 'course' : 'lesson',
            'bookmarkable_id' => (int) $this->bookmarkable_id,
            'title' => $title ?? '',
            'subtitle' => $subtitle,
            'thumbnail_url' => $thumbnail,
            'course_id' => (int) $courseId,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
