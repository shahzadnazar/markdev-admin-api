<?php

namespace Tests\Feature\Api;

use App\Models\Certificate;
use App\Models\Comment;
use App\Models\LearningActivity;
use App\Models\PointEvent;

class LessonTest extends ApiTestCase
{
    public function test_lesson_detail_requires_enrollment_unless_preview(): void
    {
        $this->actingAsStudent();
        [$course, , $lessons] = $this->makeCourse(2);

        // Lesson 1 is a preview, lesson 2 is not.
        $this->getJson("/api/v1/courses/{$course->id}/lessons/{$lessons[0]->id}")->assertOk();
        $this->getJson("/api/v1/courses/{$course->id}/lessons/{$lessons[1]->id}")->assertForbidden();
    }

    public function test_lesson_detail_carries_navigation_and_state(): void
    {
        $user = $this->actingAsStudent();
        [$course, , $lessons] = $this->makeCourse(3);
        $this->enroll($user, $course);

        $this->getJson("/api/v1/courses/{$course->id}/lessons/{$lessons[1]->id}")->assertOk()
            ->assertJsonPath('data.id', $lessons[1]->id)
            ->assertJsonPath('data.previous_lesson_id', $lessons[0]->id)
            ->assertJsonPath('data.next_lesson_id', $lessons[2]->id)
            ->assertJsonPath('data.is_completed', false)
            ->assertJsonPath('data.is_bookmarked', false)
            ->assertJsonStructure(['data' => ['content', 'video', 'resources', 'quiz_id', 'assignment_id']]);
    }

    public function test_lesson_from_another_course_is_not_found(): void
    {
        $user = $this->actingAsStudent();
        [$course] = $this->makeCourse(1);
        [, , $foreignLessons] = $this->makeCourse(1);
        $this->enroll($user, $course);

        $this->getJson("/api/v1/courses/{$course->id}/lessons/{$foreignLessons[0]->id}")->assertNotFound();
    }

    public function test_completing_a_lesson_updates_progress_points_and_activity(): void
    {
        $user = $this->actingAsStudent();
        [$course, , $lessons] = $this->makeCourse(2);
        $enrollment = $this->enroll($user, $course);

        $this->postJson("/api/v1/courses/{$course->id}/lessons/{$lessons[0]->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.progress_percent', 50);

        $this->assertEquals(50.0, (float) $enrollment->fresh()->progress_percent);
        $this->assertNotNull($enrollment->fresh()->last_activity_at);
        $this->assertSame(10, $user->fresh()->points);
        $this->assertTrue(PointEvent::where('user_id', $user->id)->where('points', 10)->exists());

        $activity = LearningActivity::where('user_id', $user->id)->whereDate('date', now())->first();
        $this->assertSame(10, $activity->minutes);

        // Completing again is idempotent: no double points or minutes.
        $this->postJson("/api/v1/courses/{$course->id}/lessons/{$lessons[0]->id}/complete")->assertOk();
        $this->assertSame(10, $user->fresh()->points);
        $this->assertSame(10, $activity->fresh()->minutes);
    }

    public function test_completing_every_lesson_completes_the_course_and_issues_a_certificate(): void
    {
        $user = $this->actingAsStudent();
        [$course, , $lessons] = $this->makeCourse(2);
        $enrollment = $this->enroll($user, $course);

        $this->postJson("/api/v1/courses/{$course->id}/lessons/{$lessons[0]->id}/complete")->assertOk();
        $this->postJson("/api/v1/courses/{$course->id}/lessons/{$lessons[1]->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.progress_percent', 100);

        $this->assertNotNull($enrollment->fresh()->completed_at);

        $certificate = Certificate::where('user_id', $user->id)->where('course_id', $course->id)->first();
        $this->assertNotNull($certificate);
        $this->assertMatchesRegularExpression('/^MD-\d{4}-[A-Z0-9]{8}$/', $certificate->certificate_number);

        // 10 + 10 for lessons, 50 for the course.
        $this->assertSame(70, $user->fresh()->points);

        // Re-completing does not issue a second certificate or more points.
        $this->deleteJson("/api/v1/courses/{$course->id}/lessons/{$lessons[1]->id}/complete")->assertOk();
        $this->postJson("/api/v1/courses/{$course->id}/lessons/{$lessons[1]->id}/complete")->assertOk();
        $this->assertSame(1, Certificate::where('user_id', $user->id)->count());
    }

    public function test_uncompleting_a_lesson_recomputes_progress(): void
    {
        $user = $this->actingAsStudent();
        [$course, , $lessons] = $this->makeCourse(2);
        $enrollment = $this->enroll($user, $course);

        $this->postJson("/api/v1/courses/{$course->id}/lessons/{$lessons[0]->id}/complete")->assertOk();
        $this->deleteJson("/api/v1/courses/{$course->id}/lessons/{$lessons[0]->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.progress_percent', 0);

        $this->assertEquals(0.0, (float) $enrollment->fresh()->progress_percent);
    }

    public function test_completion_requires_enrollment(): void
    {
        $this->actingAsStudent();
        [$course, , $lessons] = $this->makeCourse(1);

        $this->postJson("/api/v1/courses/{$course->id}/lessons/{$lessons[0]->id}/complete")->assertForbidden();
    }

    public function test_comments_thread_and_replies(): void
    {
        $user = $this->actingAsStudent();
        [$course, , $lessons] = $this->makeCourse(1);
        $this->enroll($user, $course);
        $lesson = $lessons[0];

        $rootId = $this->postJson("/api/v1/lessons/{$lesson->id}/comments", ['body' => 'First!'])
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/v1/lessons/{$lesson->id}/comments", ['body' => 'Reply', 'parent_id' => $rootId])
            ->assertCreated();

        $this->getJson("/api/v1/lessons/{$lesson->id}/comments")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'First!')
            ->assertJsonPath('data.0.replies.0.body', 'Reply')
            ->assertJsonPath('data.0.author.id', $user->id);
    }

    public function test_comment_parent_must_belong_to_the_same_lesson(): void
    {
        $user = $this->actingAsStudent();
        [$course, , $lessons] = $this->makeCourse(2);
        $this->enroll($user, $course);

        $foreign = Comment::create([
            'lesson_id' => $lessons[1]->id,
            'user_id' => $user->id,
            'body' => 'Elsewhere',
        ]);

        $this->postJson("/api/v1/lessons/{$lessons[0]->id}/comments", [
            'body' => 'Orphan reply',
            'parent_id' => $foreign->id,
        ])->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }
}
