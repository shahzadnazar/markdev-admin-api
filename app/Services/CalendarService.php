<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\User;
use Carbon\CarbonInterface;

class CalendarService
{
    /**
     * Merged calendar for a student: assignment due dates, quiz deadlines and
     * scheduled calendar rows (global or for enrolled courses), sorted by
     * starts_at.
     *
     * @return array<int, array<string, mixed>>
     */
    public function eventsBetween(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        $enrolledCourseIds = $user->enrollments()->pluck('course_id');

        $events = [];

        $assignments = Assignment::whereIn('course_id', $enrolledCourseIds)
            ->whereBetween('due_at', [$from, $to])
            ->with('course')
            ->get();

        foreach ($assignments as $assignment) {
            $events[] = [
                'id' => $assignment->id,
                'title' => $assignment->title,
                'type' => 'assignment',
                'starts_at' => $assignment->due_at->toISOString(),
                'ends_at' => null,
                'course' => $this->courseRef($assignment->course),
                'action_url' => "/assignments/{$assignment->id}",
            ];
        }

        $quizzes = Quiz::published()
            ->whereIn('course_id', $enrolledCourseIds)
            ->whereBetween('available_until', [$from, $to])
            ->with('course')
            ->get();

        foreach ($quizzes as $quiz) {
            $events[] = [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'type' => 'quiz',
                'starts_at' => $quiz->available_until->toISOString(),
                'ends_at' => null,
                'course' => $this->courseRef($quiz->course),
                'action_url' => "/quizzes/{$quiz->id}",
            ];
        }

        $calendarEvents = CalendarEvent::whereBetween('starts_at', [$from, $to])
            ->where(fn ($query) => $query->whereNull('course_id')->orWhereIn('course_id', $enrolledCourseIds))
            ->with('course')
            ->get();

        foreach ($calendarEvents as $event) {
            $events[] = [
                'id' => $event->id,
                'title' => $event->title,
                'type' => $event->type,
                'starts_at' => $event->starts_at->toISOString(),
                'ends_at' => $event->ends_at?->toISOString(),
                'course' => $this->courseRef($event->course),
                'action_url' => $event->action_url,
            ];
        }

        usort($events, fn (array $a, array $b) => strcmp($a['starts_at'], $b['starts_at']));

        return $events;
    }

    /** @return array<string, mixed>|null */
    protected function courseRef(?Course $course): ?array
    {
        if ($course === null) {
            return null;
        }

        return [
            'id' => $course->id,
            'title' => $course->title,
            'slug' => $course->slug,
            'thumbnail_url' => $course->thumbnail_url,
        ];
    }
}
