<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\LessonCompletion;

class CourseTest extends ApiTestCase
{
    public function test_course_list_shows_published_courses_only(): void
    {
        $this->actingAsStudent();

        [$published] = $this->makeCourse();
        $this->makeCourse(2, ['status' => 'draft', 'published_at' => null]);

        $response = $this->getJson('/api/v1/courses')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $published->id)
            ->assertJsonStructure([
                'data' => [['id', 'title', 'slug', 'level', 'category', 'instructor', 'tags',
                    'modules_count', 'lessons_count', 'students_count', 'is_free', 'price',
                    'is_enrolled', 'is_bookmarked', 'enrollment', 'published_at', 'updated_at']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
                'links' => ['first', 'last', 'prev', 'next'],
            ]);
    }

    public function test_course_list_filters(): void
    {
        $user = $this->actingAsStudent();

        $category = Category::create(['name' => 'Backend', 'slug' => 'backend']);
        [$enrolled] = $this->makeCourse(2, ['category_id' => $category->id, 'level' => 'advanced']);
        [$other] = $this->makeCourse();
        $this->enroll($user, $enrolled);

        $this->getJson('/api/v1/courses?enrolled=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $enrolled->id)
            ->assertJsonPath('data.0.is_enrolled', true);

        $this->getJson('/api/v1/courses?enrolled=0')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $other->id);

        $this->getJson('/api/v1/courses?category=backend')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.category.slug', 'backend');

        $this->getJson('/api/v1/courses?level=advanced')->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/courses?search='.urlencode($other->title))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $other->id);
    }

    public function test_per_page_is_capped_at_100(): void
    {
        $this->actingAsStudent();
        $this->makeCourse();

        $this->getJson('/api/v1/courses?per_page=500')->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_course_detail_includes_enrollment_state(): void
    {
        $user = $this->actingAsStudent();
        [$course] = $this->makeCourse();
        $enrollment = $this->enroll($user, $course, ['progress_percent' => 25]);

        $this->getJson("/api/v1/courses/{$course->id}")->assertOk()
            ->assertJsonPath('data.is_enrolled', true)
            ->assertJsonPath('data.enrollment.id', $enrollment->id)
            ->assertJsonPath('data.enrollment.progress_percent', 25)
            ->assertJsonPath('data.lessons_count', 2)
            ->assertJsonPath('data.students_count', 1)
            ->assertJsonPath('data.is_bookmarked', false);
    }

    public function test_unpublished_course_detail_is_not_found(): void
    {
        $this->actingAsStudent();
        [$draft] = $this->makeCourse(1, ['status' => 'draft']);

        $this->getJson("/api/v1/courses/{$draft->id}")->assertNotFound();
    }

    public function test_enroll_creates_a_single_enrollment(): void
    {
        $user = $this->actingAsStudent();
        [$course] = $this->makeCourse();

        $this->postJson("/api/v1/courses/{$course->id}/enroll")->assertCreated()
            ->assertJsonPath('data.course_id', $course->id);

        // Idempotent: a second call reuses the same enrollment.
        $this->postJson("/api/v1/courses/{$course->id}/enroll")->assertOk();

        $this->assertSame(1, $user->enrollments()->count());
    }

    public function test_enrolling_again_after_removal_restores_the_soft_deleted_enrollment(): void
    {
        $user = $this->actingAsStudent();
        [$course] = $this->makeCourse();

        $enrollment = $this->enroll($user, $course, ['progress_percent' => 60]);
        $enrollment->delete(); // soft delete — the unique index still holds the row

        $this->postJson("/api/v1/courses/{$course->id}/enroll")->assertOk()
            ->assertJsonPath('data.course_id', $course->id);

        $enrollment->refresh();
        $this->assertNull($enrollment->deleted_at);
        $this->assertEquals(0, (float) $enrollment->progress_percent);
        $this->assertSame(1, \App\Models\Enrollment::withTrashed()->count());
    }

    public function test_modules_include_lesson_completion_flags(): void
    {
        $user = $this->actingAsStudent();
        [$course, , $lessons] = $this->makeCourse(3);
        $this->enroll($user, $course);

        LessonCompletion::create([
            'user_id' => $user->id,
            'lesson_id' => $lessons[1]->id,
            'course_id' => $course->id,
            'completed_at' => now(),
        ]);

        $this->getJson("/api/v1/courses/{$course->id}/modules")->assertOk()
            ->assertJsonPath('data.0.lessons_count', 3)
            ->assertJsonPath('data.0.duration_minutes', 30)
            ->assertJsonPath('data.0.lessons.0.is_completed', false)
            ->assertJsonPath('data.0.lessons.1.is_completed', true)
            ->assertJsonPath('data.0.lessons.2.is_completed', false);
    }

    public function test_categories_endpoint_lists_categories_with_counts(): void
    {
        $this->actingAsStudent();

        $category = Category::create(['name' => 'Backend', 'slug' => 'backend']);
        $this->makeCourse(1, ['category_id' => $category->id]);
        $this->makeCourse(1, ['category_id' => $category->id, 'status' => 'draft']);

        $this->getJson('/api/v1/categories')->assertOk()
            ->assertJsonPath('data.0.slug', 'backend')
            ->assertJsonPath('data.0.courses_count', 1);
    }
}
