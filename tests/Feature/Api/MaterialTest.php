<?php

namespace Tests\Feature\Api;

use App\Models\Enrollment;
use App\Models\LearningActivity;
use App\Models\LessonCompletion;
use App\Models\LessonResource;

class MaterialTest extends ApiTestCase
{
    protected function attachResource($lesson, string $name = 'notes.pdf'): LessonResource
    {
        return $lesson->resources()->create([
            'name' => $name,
            'file_path' => 'resources/'.$name,
            'file_type' => 'pdf',
            'size_bytes' => 1024,
        ]);
    }

    public function test_materials_list_only_covers_enrolled_courses(): void
    {
        $user = $this->actingAsStudent();

        [$mine, , $myLessons] = $this->makeCourse(1);
        [$other, , $otherLessons] = $this->makeCourse(1);
        $this->enroll($user, $mine);

        $visible = $this->attachResource($myLessons->first(), 'week-1.pdf');
        $this->attachResource($otherLessons->first(), 'hidden.pdf');

        $response = $this->getJson('/api/v1/materials')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.name', 'week-1.pdf')
            ->assertJsonPath('data.0.is_read', false)
            ->assertJsonPath('data.0.course.id', $mine->id);
    }

    public function test_mark_read_records_activity_and_is_idempotent(): void
    {
        $user = $this->actingAsStudent();
        [$course, , $lessons] = $this->makeCourse(1);
        $this->enroll($user, $course);
        $resource = $this->attachResource($lessons->first());

        $this->postJson("/api/v1/materials/{$resource->id}/read")
            ->assertOk()
            ->assertJsonPath('data.is_read', true);

        $minutes = (int) LearningActivity::where('user_id', $user->id)
            ->whereDate('date', now()->toDateString())
            ->value('minutes');
        $this->assertSame(5, $minutes);

        // Marking again neither errors nor double-counts.
        $this->postJson("/api/v1/materials/{$resource->id}/read")->assertOk();
        $this->assertSame(5, (int) LearningActivity::where('user_id', $user->id)->value('minutes'));
    }

    public function test_reading_every_file_of_a_resource_lesson_completes_it(): void
    {
        $user = $this->actingAsStudent();
        [$course, , $lessons] = $this->makeCourse(1, [], ['type' => 'resource']);
        $this->enroll($user, $course);
        $lesson = $lessons->first();

        $first = $this->attachResource($lesson, 'a.pdf');
        $second = $this->attachResource($lesson, 'b.pdf');

        $this->postJson("/api/v1/materials/{$first->id}/read")->assertOk();
        $this->assertFalse(LessonCompletion::where('user_id', $user->id)->where('lesson_id', $lesson->id)->exists());

        $this->postJson("/api/v1/materials/{$second->id}/read")->assertOk();
        $this->assertTrue(LessonCompletion::where('user_id', $user->id)->where('lesson_id', $lesson->id)->exists());

        $this->assertEqualsWithDelta(
            100.0,
            (float) Enrollment::where('user_id', $user->id)->where('course_id', $course->id)->value('progress_percent'),
            0.01,
        );
    }

    public function test_unenrolled_students_cannot_mark_materials_read(): void
    {
        $this->actingAsStudent();
        [, , $lessons] = $this->makeCourse(1);
        $resource = $this->attachResource($lessons->first());

        $this->postJson("/api/v1/materials/{$resource->id}/read")->assertForbidden();
    }
}
