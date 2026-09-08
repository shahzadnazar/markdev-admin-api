<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Course;
use App\Models\DailyAttendance;
use App\Models\Enrollment;
use App\Models\LeaveApplication;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * An instructor sees leave and attendance for their own field, and no other.
 *
 * The tie is derived, not stored: instructor -> courses.instructor_id ->
 * courses.category_id, and student -> enrollments.course_id ->
 * courses.category_id. Every assertion here goes through a real request, so
 * what is proven is the controller query and the per-record checks — not a
 * hidden nav link.
 */
class InstructorCategoryScopeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $webInstructor;

    protected Category $web;

    protected Category $graphics;

    protected Course $webCourse;

    protected Course $graphicsCourse;

    protected User $webStudent;

    protected User $graphicsStudent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Notification::fake();
        $this->academyOpensEveryDay();

        $this->admin = User::factory()->create(['name' => 'Admin']);
        $this->admin->assignRole('admin');

        $this->web = Category::create(['name' => 'Web Development', 'slug' => 'web-development']);
        $this->graphics = Category::create(['name' => 'Graphics', 'slug' => 'graphics']);

        $this->webInstructor = $this->instructor('Web Instructor');
        $graphicsInstructor = $this->instructor('Graphics Instructor');

        $this->webCourse = $this->course('Laravel Basics', $this->web, $this->webInstructor);
        $this->graphicsCourse = $this->course('Illustrator', $this->graphics, $graphicsInstructor);

        $this->webStudent = $this->studentIn($this->webCourse, 'Web Student');
        $this->graphicsStudent = $this->studentIn($this->graphicsCourse, 'Graphics Student');
    }

    /* ------------------------------- Fixtures ------------------------------ */

    protected function instructor(string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole('instructor');

        return $user;
    }

    protected function course(string $title, ?Category $category, User $instructor): Course
    {
        return Course::create([
            'title' => $title,
            'slug' => Str::slug($title.'-'.Str::random(4)),
            'excerpt' => 'A test course.',
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'is_free' => true,
            'category_id' => $category?->id,
            'instructor_id' => $instructor->id,
        ]);
    }

    protected function studentIn(Course $course, string $name): User
    {
        $student = User::factory()->create(['name' => $name]);
        $student->assignRole('student');
        $student->studentProfile()->create(['reg_no' => 'MD-'.uniqid()]);

        Enrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'enrolled_at' => now()->subMonth(),
        ]);

        return $student->fresh();
    }

    protected function applyForLeave(User $student, string $from, string $to): LeaveApplication
    {
        $leave = LeaveApplication::create([
            'user_id' => $student->id,
            'from_date' => $from,
            'to_date' => $to,
            'reason' => 'Family matter',
        ]);
        $leave->openDecisions();

        return $leave->fresh();
    }

    /* ------------------------------ Leave list ----------------------------- */

    public function test_an_instructor_sees_a_leave_request_from_their_own_category(): void
    {
        $this->applyForLeave($this->webStudent, today()->addDay()->toDateString(), today()->addDays(2)->toDateString());

        $this->actingAs($this->webInstructor)->get(route('admin.leaves.index'))
            ->assertOk()
            ->assertSee('Web Student');
    }

    public function test_an_instructor_does_not_see_another_categorys_leave_request(): void
    {
        $this->applyForLeave($this->graphicsStudent, today()->addDay()->toDateString(), today()->addDays(2)->toDateString());

        $this->actingAs($this->webInstructor)->get(route('admin.leaves.index'))
            ->assertOk()
            ->assertDontSee('Graphics Student');
    }

    public function test_the_pending_count_is_scoped_too(): void
    {
        $this->applyForLeave($this->webStudent, today()->addDay()->toDateString(), today()->addDay()->toDateString());
        $this->applyForLeave($this->graphicsStudent, today()->addDay()->toDateString(), today()->addDay()->toDateString());

        // Two pending in the academy, one of them theirs — a tab reading "2"
        // would send them looking for a row they cannot see.
        $this->actingAs($this->webInstructor)->get(route('admin.leaves.index'))
            ->assertOk()
            ->assertViewHas('pendingCount', 1);

        $this->actingAs($this->admin)->get(route('admin.leaves.index'))
            ->assertOk()
            ->assertViewHas('pendingCount', 2);
    }

    public function test_reviewing_another_categorys_request_is_forbidden(): void
    {
        $leave = $this->applyForLeave($this->graphicsStudent, today()->addDay()->toDateString(), today()->addDay()->toDateString());

        $this->actingAs($this->webInstructor)
            ->post(route('admin.leaves.review', $leave), [
                'days' => [today()->addDay()->toDateString()],
            ])
            ->assertForbidden();

        // And nothing was written by the attempt.
        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_an_instructor_can_review_a_request_in_their_own_category(): void
    {
        $from = today()->addDay()->toDateString();
        $to = today()->addDays(2)->toDateString();
        $leave = $this->applyForLeave($this->webStudent, $from, $to);

        // Partial approval works the same for an instructor as for an admin,
        // and a decline still needs a written reason (369155f).
        $this->actingAs($this->webInstructor)
            ->post(route('admin.leaves.review', $leave), ['days' => [$from]])
            ->assertSessionHasErrors('review_note');

        $this->actingAs($this->webInstructor)
            ->post(route('admin.leaves.review', $leave), [
                'days' => [$from],
                'review_note' => 'Only the first day can be spared.',
            ])
            ->assertRedirect();

        $leave->refresh();
        $this->assertSame('partially_approved', $leave->status);
        $this->assertSame($this->webInstructor->id, $leave->reviewed_by);
    }

    /* ---------------------------- Daily register --------------------------- */

    public function test_the_register_lists_only_the_instructors_own_students(): void
    {
        $this->actingAs($this->webInstructor)->get(route('admin.attendance.daily'))
            ->assertOk()
            ->assertSee('Web Student')
            ->assertDontSee('Graphics Student');
    }

    public function test_an_admin_sees_every_category_unscoped(): void
    {
        $this->actingAs($this->admin)->get(route('admin.attendance.daily'))
            ->assertOk()
            ->assertSee('Web Student')
            ->assertSee('Graphics Student');

        $this->applyForLeave($this->graphicsStudent, today()->addDay()->toDateString(), today()->addDay()->toDateString());
        $this->actingAs($this->admin)->get(route('admin.leaves.index'))
            ->assertOk()
            ->assertSee('Graphics Student');
    }

    public function test_opening_another_categorys_student_by_url_is_forbidden(): void
    {
        // Forbidden, not an empty page: the id is the caller's to choose.
        $this->actingAs($this->webInstructor)
            ->get(route('admin.attendance.daily.show', $this->graphicsStudent))
            ->assertForbidden();

        $this->actingAs($this->webInstructor)
            ->get(route('admin.attendance.daily.show-print', $this->graphicsStudent))
            ->assertForbidden();

        $this->actingAs($this->webInstructor)
            ->get(route('admin.attendance.daily.show', $this->webStudent))
            ->assertOk();
    }

    public function test_marking_another_categorys_student_is_forbidden(): void
    {
        $this->actingAs($this->webInstructor)
            ->post(route('admin.attendance.daily.mark'), [
                'user_id' => $this->graphicsStudent->id,
                'date' => today()->toDateString(),
                'status' => 'present',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('daily_attendance_records', 0);
    }

    public function test_an_instructor_can_mark_their_own_students(): void
    {
        // Before the late cutoff: past it the register only allows `late`,
        // which is the existing per-slot rule and not the category scope.
        Carbon::setTestNow(today()->setTime(9, 5));

        $this->actingAs($this->webInstructor)
            ->post(route('admin.attendance.daily.mark'), [
                'user_id' => $this->webStudent->id,
                'date' => today()->toDateString(),
                'status' => 'present',
            ])
            ->assertRedirect();

        $this->assertSame('present', DailyAttendance::where('user_id', $this->webStudent->id)->value('status'));

        Carbon::setTestNow();
    }

    public function test_correcting_another_categorys_record_is_forbidden(): void
    {
        $record = DailyAttendance::create([
            'user_id' => $this->graphicsStudent->id,
            'date' => today()->toDateString(),
            'status' => 'late',
            'source' => 'manual',
            'marked_at' => now(),
        ]);

        $this->actingAs($this->webInstructor)
            ->put(route('admin.attendance.daily.update', $record), [
                'pin' => '1234',
                'status' => 'present',
                'reason' => 'Trying it on.',
            ])
            ->assertForbidden();

        $this->assertSame('late', $record->fresh()->status);
    }

    public function test_the_absent_lock_still_holds_for_an_instructor(): void
    {
        // 459f3cc: only admin and super-admin may undo an absence, whatever
        // category it is in. Their own student, so this is the lock and not
        // the category scope refusing them.
        $record = DailyAttendance::create([
            'user_id' => $this->webStudent->id,
            'date' => today()->toDateString(),
            'status' => 'absent',
            'source' => 'auto',
            'marked_at' => now(),
        ]);

        \App\Support\AttendanceConfig::setEditPin('1234');
        Setting::forgetCached();

        $this->actingAs($this->webInstructor)
            ->put(route('admin.attendance.daily.update', $record), [
                'pin' => '1234',
                'status' => 'present',
                'reason' => 'They were here.',
            ])
            ->assertForbidden();

        $this->assertSame('absent', $record->fresh()->status);
    }

    public function test_filtering_by_another_categorys_course_is_forbidden(): void
    {
        // An empty register would read as "nobody attends this course" rather
        // than "not yours".
        $this->actingAs($this->webInstructor)
            ->get(route('admin.attendance.daily', ['course' => $this->graphicsCourse->id]))
            ->assertForbidden();

        $this->actingAs($this->webInstructor)
            ->get(route('admin.attendance.daily', ['course' => $this->webCourse->id]))
            ->assertOk();

        // The dropdown only offers what they may pick.
        $this->actingAs($this->webInstructor)->get(route('admin.attendance.daily'))
            ->assertOk()
            ->assertSee('Laravel Basics')
            ->assertDontSee('Illustrator');
    }

    /* --------------------------- Several categories ------------------------ */

    public function test_an_instructor_teaching_two_categories_sees_both_and_only_both(): void
    {
        $design = Category::create(['name' => 'Design', 'slug' => 'design']);
        $designCourse = $this->course('Figma', $design, $this->webInstructor);
        $designStudent = $this->studentIn($designCourse, 'Design Student');

        $this->actingAs($this->webInstructor)->get(route('admin.attendance.daily'))
            ->assertOk()
            ->assertSee('Web Student')
            ->assertSee('Design Student')
            ->assertDontSee('Graphics Student');

        // Both categories are named, and each row says which it is — with two
        // categories in the list a bare name is ambiguous.
        $this->actingAs($this->webInstructor)->get(route('admin.attendance.daily'))
            ->assertSee('Web Development')
            ->assertSee('Design');

        $this->actingAs($this->webInstructor)
            ->get(route('admin.attendance.daily.show', $designStudent))
            ->assertOk();
    }

    public function test_two_categories_sharing_a_name_are_named_once(): void
    {
        // Two rows of "Web Development and Web Development" tells a reader
        // nothing, and a per-row label that is the same word everywhere is
        // not worth the column.
        $twin = Category::create(['name' => 'Web Development', 'slug' => 'web-development-evening']);
        $twinCourse = $this->course('Laravel Evenings', $twin, $this->webInstructor);
        $this->studentIn($twinCourse, 'Evening Student');

        $this->actingAs($this->webInstructor)->get(route('admin.attendance.daily'))
            ->assertOk()
            ->assertSee('Evening Student')
            ->assertSee('Showing students in')
            ->assertDontSee('Web Development and Web Development')
            ->assertViewHas('categoryLabels', fn ($labels) => $labels->isEmpty());
    }

    public function test_one_category_needs_no_per_row_label(): void
    {
        // Every row would carry the same label, which says nothing.
        $this->actingAs($this->webInstructor)->get(route('admin.attendance.daily'))
            ->assertOk()
            ->assertViewHas('categoryLabels', fn ($labels) => $labels->isEmpty());
    }

    /* ------------------------------ Edge cases ----------------------------- */

    public function test_an_uncategorised_course_is_not_a_skeleton_key(): void
    {
        // courses.category_id is nullable. An instructor whose only course has
        // no category must see nobody rather than everybody.
        $stray = $this->instructor('Stray Instructor');
        $this->course('Uncategorised', null, $stray);

        $this->actingAs($stray)->get(route('admin.attendance.daily'))
            ->assertOk()
            ->assertDontSee('Web Student')
            ->assertDontSee('Graphics Student');

        $this->actingAs($stray)
            ->get(route('admin.attendance.daily.show', $this->webStudent))
            ->assertForbidden();
    }

    public function test_a_manager_is_not_scoped_by_category(): void
    {
        // Managers hold the unscoped permission. If they ever also teach a
        // course, that must not silently narrow what they see.
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $this->course('A course a manager happens to teach', $this->web, $manager);

        $this->actingAs($manager)->get(route('admin.attendance.daily'))
            ->assertOk()
            ->assertSee('Web Student')
            ->assertSee('Graphics Student');
    }

    /* ----------------------------- Still refused --------------------------- */

    public function test_an_instructor_still_cannot_reach_settings_slots_holidays_or_billing(): void
    {
        foreach ([
            'admin.settings.edit',
            'admin.attendance-slots.index',
            'admin.holidays.index',
            'admin.rules.index',
        ] as $route) {
            $this->actingAs($this->webInstructor)->get(route($route))
                ->assertForbidden();
        }

        $this->actingAs($this->webInstructor)->get('/admin/billing/invoices')->assertForbidden();
    }

    public function test_bulk_present_does_not_reach_outside_the_category(): void
    {
        // Before the late cutoff, or nobody is markable present at all.
        Carbon::setTestNow(today()->setTime(9, 5));

        $this->actingAs($this->webInstructor)
            ->post(route('admin.attendance.daily.bulk'), ['date' => today()->toDateString()])
            ->assertRedirect();

        $this->assertTrue(DailyAttendance::where('user_id', $this->webStudent->id)->exists());
        $this->assertFalse(DailyAttendance::where('user_id', $this->graphicsStudent->id)->exists());

        Carbon::setTestNow();
    }
}
