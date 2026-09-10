<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Course;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Trashed filter is only offered to someone who could act on it.
 *
 * Three list screens carry one: Courses, Users and Students. Each reaches more
 * roles than can delete or restore there, so the filter was an empty room
 * people were invited into — an instructor on Courses, a manager on all three.
 *
 * Presentation only. The last group of tests is the point: every restore,
 * delete and force-delete route still refuses at the server with the checkbox
 * gone. A hidden control is not a permission.
 */
class TrashFilterVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function userWith(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    protected function trashedCourse(): Course
    {
        $category = Category::create(['name' => 'Web', 'slug' => 'web-'.Str::random(4)]);
        $course = Course::create([
            'title' => 'A binned course',
            'slug' => Str::slug('binned-'.Str::random(4)),
            'excerpt' => 'x',
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'is_free' => true,
            'category_id' => $category->id,
        ]);
        $course->delete();

        return $course;
    }

    protected function trashedStaffUser(): User
    {
        $user = User::factory()->create(['name' => 'Binned Staffer']);
        $user->assignRole('manager');
        $user->delete();

        return $user;
    }

    protected function trashedStudent(): User
    {
        $student = User::factory()->create(['name' => 'Binned Student']);
        $student->assignRole('student');
        $student->delete();

        return $student;
    }

    /* ------------------------------- Courses -------------------------------- */

    public function test_an_instructor_sees_no_trashed_checkbox_on_courses(): void
    {
        $this->actingAs($this->userWith('instructor'))
            ->get(route('admin.courses.index'))->assertOk()
            ->assertDontSee('name="trashed"', false);
    }

    public function test_a_manager_sees_no_trashed_checkbox_on_courses_either(): void
    {
        // Not in the report, but the same hole: a manager holds courses.view
        // and neither courses.delete nor courses.restore.
        $this->actingAs($this->userWith('manager'))
            ->get(route('admin.courses.index'))->assertOk()
            ->assertDontSee('name="trashed"', false);
    }

    public function test_an_admin_sees_the_trashed_checkbox_on_courses(): void
    {
        $this->actingAs($this->userWith('admin'))
            ->get(route('admin.courses.index'))->assertOk()
            ->assertSee('name="trashed"', false)
            ->assertSee('Trashed', false);
    }

    public function test_an_admin_asking_for_trashed_courses_gets_them(): void
    {
        $course = $this->trashedCourse();

        $this->actingAs($this->userWith('admin'))
            ->get(route('admin.courses.index', ['trashed' => 1]))->assertOk()
            ->assertSee($course->title, false);
    }

    public function test_an_instructor_asking_for_trashed_courses_gets_the_normal_list(): void
    {
        $binned = $this->trashedCourse();
        $live = Course::create([
            'title' => 'A live course',
            'slug' => Str::slug('live-'.Str::random(4)),
            'excerpt' => 'x',
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'is_free' => true,
            'category_id' => $binned->category_id,
            'instructor_id' => ($i = $this->userWith('instructor'))->id,
        ]);

        // Ignored, not refused: a stray query string is a copied link, not an
        // attack, and a 403 on a list page tells someone they did something
        // wrong when they did not.
        $this->actingAs($i)
            ->get(route('admin.courses.index', ['trashed' => 1]))->assertOk()
            ->assertSee($live->title, false)
            ->assertDontSee($binned->title, false);
    }

    /* -------------------------------- Users --------------------------------- */

    public function test_a_manager_sees_no_trashed_checkbox_on_users(): void
    {
        $this->actingAs($this->userWith('manager'))
            ->get(route('admin.users.index'))->assertOk()
            ->assertDontSee('name="trashed"', false);
    }

    public function test_an_admin_sees_and_can_use_the_trashed_checkbox_on_users(): void
    {
        $binned = $this->trashedStaffUser();

        $this->actingAs($this->userWith('admin'))
            ->get(route('admin.users.index'))->assertOk()
            ->assertSee('name="trashed"', false);

        $this->actingAs($this->userWith('admin'))
            ->get(route('admin.users.index', ['trashed' => 1]))->assertOk()
            ->assertSee($binned->name, false);
    }

    public function test_a_manager_asking_for_trashed_users_gets_the_normal_list(): void
    {
        $binned = $this->trashedStaffUser();
        $manager = $this->userWith('manager');

        $this->actingAs($manager)
            ->get(route('admin.users.index', ['trashed' => 1]))->assertOk()
            ->assertDontSee($binned->name, false)
            ->assertSee($manager->name, false);
    }

    /* ------------------------------- Students -------------------------------- */

    public function test_a_manager_sees_no_trash_box_on_students(): void
    {
        $this->actingAs($this->userWith('manager'))
            ->get(route('admin.students.index'))->assertOk()
            ->assertDontSee('name="trashed"', false);
    }

    public function test_an_admin_sees_and_can_use_the_trash_box_on_students(): void
    {
        $binned = $this->trashedStudent();

        $this->actingAs($this->userWith('admin'))
            ->get(route('admin.students.index'))->assertOk()
            ->assertSee('name="trashed"', false);

        $this->actingAs($this->userWith('admin'))
            ->get(route('admin.students.index', ['trashed' => 1]))->assertOk()
            ->assertSee($binned->name, false);
    }

    /**
     * The students checkbox was already gated; the URL was not.
     *
     * That is the half-fix this pass completes — the screen looked right and
     * a copied link still opened a trash box whose every action refuses.
     */
    public function test_a_manager_asking_for_the_students_trash_box_gets_the_normal_list(): void
    {
        $binned = $this->trashedStudent();
        $live = User::factory()->create(['name' => 'Live Student']);
        $live->assignRole('student');

        $this->actingAs($this->userWith('manager'))
            ->get(route('admin.students.index', ['trashed' => 1]))->assertOk()
            ->assertSee($live->name, false)
            ->assertDontSee($binned->name, false);
    }

    /* ------------------- The rule is still on the server --------------------- */

    /**
     * The checkbox is gone; the refusal is not. Each of these is the write the
     * hidden filter used to lead to.
     */
    public function test_an_instructor_is_still_refused_every_course_write(): void
    {
        $course = $this->trashedCourse();
        $instructor = $this->userWith('instructor');

        $this->actingAs($instructor)->post(route('admin.courses.restore', $course))->assertForbidden();
        $this->actingAs($instructor)->delete(route('admin.courses.force-destroy', $course))->assertForbidden();

        $live = Course::withTrashed()->find($course->id);
        $live->restore();
        $this->actingAs($instructor)->delete(route('admin.courses.destroy', $live))->assertForbidden();

        $this->assertNotNull(Course::find($live->id), 'Still there, and not trashed.');
    }

    public function test_a_manager_is_still_refused_every_course_write(): void
    {
        $course = $this->trashedCourse();
        $manager = $this->userWith('manager');

        $this->actingAs($manager)->post(route('admin.courses.restore', $course))->assertForbidden();
        $this->actingAs($manager)->delete(route('admin.courses.force-destroy', $course))->assertForbidden();
        $this->assertNotNull(Course::withTrashed()->find($course->id));
    }

    public function test_a_manager_is_still_refused_every_user_write(): void
    {
        $binned = $this->trashedStaffUser();
        $manager = $this->userWith('manager');

        $this->actingAs($manager)->post(route('admin.users.restore', $binned))->assertForbidden();
        $this->actingAs($manager)->delete(route('admin.users.force-destroy', $binned))->assertForbidden();
        $this->assertNotNull(User::withTrashed()->find($binned->id));
    }

    public function test_a_manager_is_still_refused_every_student_write(): void
    {
        $binned = $this->trashedStudent();
        $manager = $this->userWith('manager');

        $this->actingAs($manager)->post(route('admin.students.restore', $binned))->assertForbidden();
        $this->actingAs($manager)->delete(route('admin.students.force-destroy', $binned))->assertForbidden();
        $this->assertNotNull(User::withTrashed()->find($binned->id));
    }

    public function test_an_admin_can_still_restore(): void
    {
        // The other side of it: nothing here narrowed what an admin may do.
        $course = $this->trashedCourse();

        $this->actingAs($this->userWith('admin'))
            ->post(route('admin.courses.restore', $course))
            ->assertRedirect();

        $this->assertNotNull(Course::find($course->id));
    }
}
