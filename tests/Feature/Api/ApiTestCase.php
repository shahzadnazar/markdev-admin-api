<?php

namespace Tests\Feature\Api;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Module;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function student(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole('student');

        return $user;
    }

    protected function actingAsStudent(?User $user = null): User
    {
        $user ??= $this->student();

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * A published course with a single module holding $lessonCount lessons.
     *
     * @return array{0: Course, 1: Module, 2: \Illuminate\Support\Collection<int, Lesson>}
     */
    protected function makeCourse(int $lessonCount = 2, array $overrides = [], array $lessonOverrides = []): array
    {
        $title = 'Course '.Str::random(8);

        $course = Course::create(array_merge([
            'title' => $title,
            'slug' => Str::slug($title),
            'excerpt' => 'A test course.',
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'is_free' => true,
            'duration_minutes' => $lessonCount * 10,
        ], $overrides));

        $module = Module::create([
            'course_id' => $course->id,
            'title' => 'Module 1',
            'position' => 1,
        ]);

        $lessons = collect(range(1, max($lessonCount, 1)))->map(fn (int $i) => Lesson::create(array_merge([
            'module_id' => $module->id,
            'course_id' => $course->id,
            'title' => "Lesson {$i}",
            'type' => 'video',
            'duration_minutes' => 10,
            'position' => $i,
            'is_preview' => $i === 1,
        ], $lessonOverrides)));

        return [$course->fresh(), $module, $lessons];
    }

    protected function enroll(User $user, Course $course, array $overrides = []): Enrollment
    {
        return Enrollment::create(array_merge([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'enrolled_at' => now()->subDay(),
        ], $overrides));
    }
}
