<?php

namespace Tests\Feature\Api;

use App\Models\Bookmark;
use App\Models\Category;
use App\Models\Course;
use App\Models\Lesson;

class BookmarkTest extends ApiTestCase
{
    public function test_bookmarks_can_be_added_listed_and_removed(): void
    {
        $user = $this->actingAsStudent();

        $category = Category::create(['name' => 'Backend', 'slug' => 'backend']);
        [$course, , $lessons] = $this->makeCourse(1, ['category_id' => $category->id]);

        $this->postJson('/api/v1/bookmarks', ['type' => 'course', 'id' => $course->id])
            ->assertCreated()
            ->assertJsonPath('data.type', 'course')
            ->assertJsonPath('data.bookmarkable_id', $course->id)
            ->assertJsonPath('data.title', $course->title)
            ->assertJsonPath('data.subtitle', 'Backend')
            ->assertJsonPath('data.course_id', $course->id);

        // Adding twice does not duplicate.
        $this->postJson('/api/v1/bookmarks', ['type' => 'course', 'id' => $course->id])->assertOk();
        $this->assertSame(1, Bookmark::count());

        $this->postJson('/api/v1/bookmarks', ['type' => 'lesson', 'id' => $lessons[0]->id])
            ->assertCreated()
            ->assertJsonPath('data.type', 'lesson')
            ->assertJsonPath('data.subtitle', $course->title)
            ->assertJsonPath('data.course_id', $course->id);

        $this->getJson('/api/v1/bookmarks')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/bookmarks?type=lesson')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'lesson');

        $this->deleteJson("/api/v1/bookmarks/course/{$course->id}")->assertNoContent();
        $this->assertSame(1, Bookmark::where('user_id', $user->id)->count());

        // Course detail reflects the bookmark flag.
        $this->postJson('/api/v1/bookmarks', ['type' => 'course', 'id' => $course->id]);
        $this->getJson("/api/v1/courses/{$course->id}")->assertOk()
            ->assertJsonPath('data.is_bookmarked', true);
    }

    public function test_bookmark_validation(): void
    {
        $this->actingAsStudent();

        $this->postJson('/api/v1/bookmarks', ['type' => 'course', 'id' => 999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('id');

        $this->postJson('/api/v1/bookmarks', ['type' => 'author', 'id' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_bookmarks_are_scoped_to_the_owner(): void
    {
        $other = $this->student();
        [$course] = $this->makeCourse(1);
        Bookmark::create([
            'user_id' => $other->id,
            'bookmarkable_type' => Course::class,
            'bookmarkable_id' => $course->id,
        ]);

        $this->actingAsStudent();
        $this->getJson('/api/v1/bookmarks')->assertOk()->assertJsonCount(0, 'data');
    }
}
