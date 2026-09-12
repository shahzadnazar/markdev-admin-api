<?php

namespace Tests\Feature\Admin;

use App\Support\PrivateFiles;
use App\Models\Category;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonResource;
use App\Models\Module;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Resources on the course itself, in the same table as lesson resources.
 *
 * One table (option a) rather than a second course_resources: the kind/url/file
 * discriminator, its validation, the YouTube host match and the http-only rule
 * all came from 589e4db and would otherwise exist twice. Two copies of an
 * invariant is how they drift, and one of these is a security rule.
 *
 * The price is a second invariant beside file-or-link — exactly one owner —
 * and these pin it from both sides.
 */
class CourseResourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Course $course;

    protected Category $web;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('public');
        Storage::fake(PrivateFiles::DISK);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->web = Category::create(['name' => 'Web', 'slug' => 'web-'.Str::random(4)]);
        $this->course = $this->makeCourse('Advanced Web Development', $this->web);
    }

    protected function makeCourse(string $title, Category $category, ?User $instructor = null): Course
    {
        return Course::create([
            'title' => $title, 'slug' => Str::slug($title.'-'.Str::random(4)), 'excerpt' => 'x',
            'level' => 'beginner', 'status' => 'published', 'published_at' => now()->subDay(),
            'is_free' => true, 'category_id' => $category->id,
            'instructor_id' => $instructor?->id,
        ]);
    }

    protected function addResource(array $payload, ?User $as = null, ?Course $course = null)
    {
        $course ??= $this->course;

        return $this->actingAs($as ?? $this->admin)
            ->from(route('admin.courses.show', $course))
            ->post(route('admin.courses.resources.store', $course), $payload);
    }

    // ------------------------------------------------------- the two owners

    public function test_a_course_level_link_is_stored_against_the_course_not_a_lesson(): void
    {
        $this->addResource([
            'kind' => 'link', 'link_name' => 'Syllabus', 'link_url' => 'https://laravel.com/docs',
        ])->assertRedirect();

        $resource = LessonResource::firstOrFail();

        $this->assertSame($this->course->id, $resource->course_id);
        $this->assertNull($resource->lesson_id);
        $this->assertSame('https://laravel.com/docs', $resource->target_url);
    }

    public function test_a_course_level_file_still_uploads(): void
    {
        $this->addResource([
            'kind' => 'file', 'file' => UploadedFile::fake()->create('syllabus.pdf', 40, 'application/pdf'),
        ])->assertRedirect();

        $resource = LessonResource::firstOrFail();

        $this->assertSame('file', $resource->kind);
        Storage::disk(PrivateFiles::DISK)->assertExists($resource->file_path);
    }

    public function test_exactly_one_owner_is_enforced(): void
    {
        // The second invariant this table now carries. A row with both owners
        // or neither is a bug the moment it is written, so it throws rather
        // than being stored and found later.
        $lesson = $this->lessonIn($this->course);

        foreach ([
            'neither' => ['name' => 'x', 'kind' => 'link', 'url' => 'https://a.test'],
            'both' => ['name' => 'x', 'kind' => 'link', 'url' => 'https://a.test',
                'lesson_id' => $lesson->id, 'course_id' => $this->course->id],
        ] as $label => $attributes) {
            try {
                LessonResource::create($attributes);
                $this->fail("a resource with {$label} owner was allowed");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('exactly one', $e->getMessage());
            }
        }
    }

    public function test_course_resources_and_lesson_resources_do_not_mix(): void
    {
        // The relation on each side excludes the other's rows, so neither list
        // sweeps up the wrong level.
        $lesson = $this->lessonIn($this->course);
        $lesson->resources()->create(['name' => 'lesson file', 'kind' => 'link', 'url' => 'https://a.test']);
        $this->course->resources()->create(['name' => 'course file', 'kind' => 'link', 'url' => 'https://b.test']);

        $this->assertSame(['lesson file'], $lesson->fresh()->resources->pluck('name')->all());
        $this->assertSame(['course file'], $this->course->fresh()->resources->pluck('name')->all());
    }

    public function test_lesson_completion_still_ignores_course_level_resources(): void
    {
        /*
         * The audit that decided one table over two. LessonProgressService
         * counts unread materials through $lesson->resources(), which is
         * scoped by lesson_id — so a course-level row can never hold a
         * lesson's completion hostage.
         */
        $lesson = $this->lessonIn($this->course);
        $this->course->resources()->create(['name' => 'course link', 'kind' => 'link', 'url' => 'https://b.test']);

        $this->assertSame(0, $lesson->resources()->count());
    }

    // ------------------------------------------------------- the same rules

    public function test_a_javascript_url_is_rejected_at_the_course_level_too(): void
    {
        $this->addResource(['kind' => 'link', 'link_name' => 'bad', 'link_url' => 'javascript:alert(1)'])
            ->assertSessionHasErrors('link_url');

        $this->assertSame(0, LessonResource::count());
    }

    public function test_youtube_is_matched_by_host_here_as_well(): void
    {
        $this->addResource(['kind' => 'link', 'link_name' => 'Not really', 'link_url' => 'https://youtube.com.phishing.example/x'])
            ->assertRedirect();

        $this->assertFalse(LessonResource::firstOrFail()->is_youtube);
    }

    // -------------------------------------------------------- who may add

    public function test_an_instructor_can_add_one_to_their_own_course(): void
    {
        $instructor = User::factory()->create();
        $instructor->assignRole('instructor');
        $own = $this->makeCourse('Their own course', $this->web, $instructor);

        $this->addResource(
            ['kind' => 'link', 'link_name' => 'Notes', 'link_url' => 'https://laravel.com'],
            $instructor,
            $own,
        )->assertRedirect();

        $this->assertSame(1, $own->resources()->count());
    }

    public function test_an_instructor_cannot_add_one_to_another_instructors_course(): void
    {
        // Category scoping (77b6fd1) still decides, on the route as well as
        // in the view.
        $instructor = User::factory()->create();
        $instructor->assignRole('instructor');
        $theirs = $this->makeCourse('Someone elses', $this->web, User::factory()->create());

        $this->addResource(
            ['kind' => 'link', 'link_name' => 'x', 'link_url' => 'https://laravel.com'],
            $instructor,
            $theirs,
        )->assertForbidden();

        $this->assertSame(0, LessonResource::count());
    }

    public function test_a_role_without_courses_update_is_refused(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        // Manager holds courses.update, so the one that must not is a student.
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->addResource(['kind' => 'link', 'link_name' => 'x', 'link_url' => 'https://a.test'], $student)
            ->assertForbidden();
    }

    public function test_a_resource_from_another_course_cannot_be_deleted_through_this_one(): void
    {
        $other = $this->makeCourse('Other', $this->web);
        $resource = $other->resources()->create(['name' => 'theirs', 'kind' => 'link', 'url' => 'https://a.test']);

        $this->actingAs($this->admin)
            ->delete(route('admin.courses.resources.destroy', [$this->course, $resource]))
            ->assertNotFound();

        $this->assertSame(1, LessonResource::count());
    }

    public function test_the_builder_shows_the_section_and_its_add_form(): void
    {
        $this->course->resources()->create(['name' => 'Syllabus', 'kind' => 'link', 'url' => 'https://laravel.com']);

        $page = $this->actingAs($this->admin)
            ->get(route('admin.courses.show', $this->course))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Course resources', $page);
        $this->assertStringContainsString('Syllabus', $page);
        $this->assertStringContainsString(route('admin.courses.resources.store', $this->course), $page);
    }

    // ------------------------------------------- and out to the student

    public function test_an_enrolled_student_sees_it_on_the_notes_endpoint(): void
    {
        $this->course->resources()->create(['name' => 'Course syllabus', 'kind' => 'link', 'url' => 'https://laravel.com/docs']);

        $student = User::factory()->create();
        $student->assignRole('student');
        Enrollment::create([
            'user_id' => $student->id, 'course_id' => $this->course->id,
            'enrolled_at' => now(), 'progress_percent' => 0,
        ]);
        Sanctum::actingAs($student);

        $this->getJson('/api/v1/notes')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Course syllabus')
            ->assertJsonPath('data.0.source', 'resource')
            ->assertJsonPath('data.0.kind', 'link')
            ->assertJsonPath('data.0.url', 'https://laravel.com/docs');
    }

    public function test_a_student_who_is_not_enrolled_does_not(): void
    {
        // Enforced on the query, not by the portal choosing not to render it.
        $this->course->resources()->create(['name' => 'Course syllabus', 'kind' => 'link', 'url' => 'https://laravel.com/docs']);

        $outsider = User::factory()->create();
        $outsider->assignRole('student');
        Sanctum::actingAs($outsider);

        $this->getJson('/api/v1/notes')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_lesson_level_resources_reach_the_student_here_too(): void
    {
        // They used to live on the lesson player's Resources tab. That tab is
        // gone, so this endpoint is the only route from an instructor adding a
        // lesson resource to a student seeing it.
        $lesson = $this->lessonIn($this->course);
        $lesson->resources()->create(['name' => 'Lesson handout', 'kind' => 'link', 'url' => 'https://a.test']);

        $student = User::factory()->create();
        $student->assignRole('student');
        Enrollment::create([
            'user_id' => $student->id, 'course_id' => $this->course->id,
            'enrolled_at' => now(), 'progress_percent' => 0,
        ]);
        Sanctum::actingAs($student);

        $this->getJson('/api/v1/notes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Lesson handout')
            ->assertJsonPath('data.0.description', 'From lesson: '.$lesson->title);
    }

    public function test_private_notes_never_appear_on_the_notes_endpoint(): void
    {
        // Three different things share the word "notes". This is the one that
        // would matter most to get wrong.
        $lesson = $this->lessonIn($this->course);
        $student = User::factory()->create();
        $student->assignRole('student');
        Enrollment::create([
            'user_id' => $student->id, 'course_id' => $this->course->id,
            'enrolled_at' => now(), 'progress_percent' => 0,
        ]);
        \App\Models\LessonPrivateNote::create([
            'lesson_id' => $lesson->id, 'user_id' => $student->id, 'body' => 'MY-PRIVATE-WRITING',
        ]);
        Sanctum::actingAs($student);

        $this->assertStringNotContainsString(
            'MY-PRIVATE-WRITING',
            $this->getJson('/api/v1/notes')->assertOk()->getContent(),
        );
    }

    protected function lessonIn(Course $course): Lesson
    {
        $module = Module::create(['course_id' => $course->id, 'title' => 'M1', 'position' => 1]);

        return Lesson::create([
            'module_id' => $module->id, 'course_id' => $course->id,
            'title' => 'L1', 'type' => 'video', 'position' => 1,
        ]);
    }
}
