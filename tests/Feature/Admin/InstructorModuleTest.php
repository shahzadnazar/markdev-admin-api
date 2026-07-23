<?php

namespace Tests\Feature\Admin;

use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstructorModuleTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $instructor;

    protected User $otherInstructor;

    protected Course $ownCourse;

    protected Course $foreignCourse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->instructor = User::factory()->create(['headline' => 'Lead Instructor']);
        $this->instructor->assignRole('instructor');

        $this->otherInstructor = User::factory()->create();
        $this->otherInstructor->assignRole('instructor');

        $this->ownCourse = $this->course($this->instructor, 'My Own Course');
        $this->foreignCourse = $this->course($this->otherInstructor, 'Someone Elses Course');
    }

    protected function course(User $instructor, string $title): Course
    {
        return Course::create([
            'title' => $title,
            'slug' => Str::slug($title.'-'.Str::random(4)),
            'excerpt' => 'Test course.',
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'is_free' => true,
            'instructor_id' => $instructor->id,
        ]);
    }

    /* ------------------------- Faculty directory ------------------------- */

    public function test_admin_sees_instructor_directory(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/instructors');

        $response->assertOk()
            ->assertSee('Instructor management')
            ->assertSee($this->instructor->name)
            ->assertSee($this->otherInstructor->name);
    }

    public function test_instructor_profile_page_shows_their_courses(): void
    {
        $response = $this->actingAs($this->admin)->get("/admin/instructors/{$this->instructor->id}");

        $response->assertOk()
            ->assertSee($this->instructor->name)
            ->assertSee('My Own Course')
            ->assertDontSee('Someone Elses Course');
    }

    public function test_instructor_directory_rejects_non_instructor_ids(): void
    {
        $this->actingAs($this->admin)
            ->get("/admin/instructors/{$this->admin->id}")
            ->assertNotFound();
    }

    public function test_instructor_cannot_open_the_directory(): void
    {
        $this->actingAs($this->instructor)->get('/admin/instructors')->assertForbidden();
    }

    public function test_instructor_cannot_open_biometric_devices(): void
    {
        $this->actingAs($this->instructor)->get('/admin/biometric/devices')->assertForbidden();
    }

    /* --------------------------- Course scoping --------------------------- */

    public function test_instructor_course_list_shows_only_their_courses(): void
    {
        $response = $this->actingAs($this->instructor)->get('/admin/courses');

        $response->assertOk()
            ->assertSee('My Own Course')
            ->assertDontSee('Someone Elses Course');
    }

    public function test_instructor_cannot_edit_a_foreign_course(): void
    {
        $this->actingAs($this->instructor)
            ->get("/admin/courses/{$this->foreignCourse->id}/edit")
            ->assertForbidden();
    }

    public function test_admin_still_sees_every_course(): void
    {
        $this->actingAs($this->admin)->get('/admin/courses')
            ->assertOk()
            ->assertSee('My Own Course')
            ->assertSee('Someone Elses Course');
    }

    /* ------------------------- Assignment scoping ------------------------- */

    public function test_instructor_cannot_create_assignment_for_foreign_course(): void
    {
        $this->actingAs($this->instructor)
            ->post('/admin/assignments', [
                'course_id' => $this->foreignCourse->id,
                'title' => 'Sneaky assignment',
                'max_score' => 100,
            ])
            ->assertForbidden();
    }

    public function test_instructor_can_create_assignment_for_own_course(): void
    {
        $this->actingAs($this->instructor)
            ->post('/admin/assignments', [
                'course_id' => $this->ownCourse->id,
                'title' => 'Week 1 homework',
                'max_score' => 100,
            ])
            ->assertRedirect('/admin/assignments');

        $this->assertDatabaseHas('assignments', [
            'course_id' => $this->ownCourse->id,
            'title' => 'Week 1 homework',
        ]);
    }

    public function test_instructor_cannot_open_foreign_submissions(): void
    {
        $assignment = Assignment::create([
            'course_id' => $this->foreignCourse->id,
            'title' => 'Foreign homework',
            'max_score' => 100,
        ]);

        $this->actingAs($this->instructor)
            ->get("/admin/assignments/{$assignment->id}/submissions")
            ->assertForbidden();
    }

    /* ------------------------ Announcement scoping ------------------------ */

    public function test_instructor_must_target_their_own_course(): void
    {
        $this->actingAs($this->instructor)
            ->from('/admin/announcements/create')
            ->post('/admin/announcements', [
                'title' => 'Global blast',
                'body' => 'Hello everyone',
            ])
            ->assertSessionHasErrors('course_id');

        $this->actingAs($this->instructor)
            ->post('/admin/announcements', [
                'title' => 'Foreign blast',
                'body' => 'Hello other class',
                'course_id' => $this->foreignCourse->id,
            ])
            ->assertForbidden();

        $this->actingAs($this->instructor)
            ->post('/admin/announcements', [
                'title' => 'Class update',
                'body' => 'Lecture moved to 3pm',
                'course_id' => $this->ownCourse->id,
            ])
            ->assertRedirect('/admin/announcements');

        $this->assertDatabaseHas('announcements', [
            'title' => 'Class update',
            'course_id' => $this->ownCourse->id,
            'author_id' => $this->instructor->id,
        ]);
    }

    public function test_instructor_announcement_list_is_scoped(): void
    {
        Announcement::create([
            'title' => 'Academy wide notice',
            'body' => 'For everyone',
            'author_id' => $this->admin->id,
            'published_at' => now(),
        ]);
        Announcement::create([
            'title' => 'Own class notice',
            'body' => 'For my class',
            'course_id' => $this->ownCourse->id,
            'author_id' => $this->instructor->id,
            'published_at' => now(),
        ]);

        $this->actingAs($this->instructor)->get('/admin/announcements')
            ->assertOk()
            ->assertSee('Own class notice')
            ->assertDontSee('Academy wide notice');
    }

    /* ------------------------- Attendance scoping ------------------------- */

    public function test_instructor_cannot_mark_attendance_for_foreign_course(): void
    {
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->actingAs($this->instructor)
            ->post('/admin/attendance', [
                'course_id' => $this->foreignCourse->id,
                'date' => today()->toDateString(),
                'rows' => [
                    ['user_id' => $student->id, 'status' => 'present'],
                ],
            ])
            ->assertForbidden();
    }

    /* ----------------------------- Dashboard ----------------------------- */

    public function test_instructor_gets_the_classroom_dashboard(): void
    {
        $this->actingAs($this->instructor)->get('/admin')
            ->assertOk()
            ->assertSee('My classroom')
            ->assertSee('My Own Course')
            ->assertDontSee('System health');
    }

    public function test_admin_keeps_the_full_dashboard(): void
    {
        $this->actingAs($this->admin)->get('/admin')
            ->assertOk()
            ->assertSee('System health');
    }
}
