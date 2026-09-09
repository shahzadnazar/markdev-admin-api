<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Course;
use App\Models\DailyAttendance;
use App\Models\Enrollment;
use App\Models\User;
use App\Support\AttendanceConfig;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Mark dialog opens on the question it is for.
 *
 * It used to lead with a block of prior attendance — four count cards, a rate,
 * the last five days — above the Status field, which pushed the thing being
 * asked below the fold. The block is gone, and so are the two queries per page
 * load that existed only to fill it. The student's history is still a click
 * away on their own page, which is where a history belongs.
 */
class MarkModalTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $instructor;

    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->academyOpensEveryDay();
        AttendanceConfig::setEditPin('1234');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $category = Category::create(['name' => 'Web', 'slug' => 'web-'.Str::random(4)]);

        $this->instructor = User::factory()->create();
        $this->instructor->assignRole('instructor');

        $course = Course::create([
            'title' => 'A course',
            'slug' => Str::slug('a-course-'.Str::random(4)),
            'excerpt' => 'x',
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now()->subYear(),
            'is_free' => true,
            'category_id' => $category->id,
            'instructor_id' => $this->instructor->id,
        ]);

        $this->student = User::factory()->create(['name' => 'A Student']);
        $this->student->assignRole('student');
        Enrollment::create([
            'user_id' => $this->student->id,
            'course_id' => $course->id,
            'enrolled_at' => now()->subYear(),
        ]);
    }

    protected function register(User $as): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as)
            ->get(route('admin.attendance.daily', ['date' => today()->toDateString()]));
    }

    /* ------------------------------- The block ------------------------------ */

    public function test_the_mark_modal_no_longer_leads_with_previous_attendance(): void
    {
        // History behind the student, so the block would have had something to
        // show — its absence is the point, not an empty state.
        foreach ([['present', 5], ['late', 4], ['absent', 3], ['leave', 2]] as [$status, $back]) {
            DailyAttendance::create([
                'user_id' => $this->student->id,
                'date' => today()->subDays($back)->toDateString(),
                'status' => $status,
                'source' => 'manual',
                'marked_at' => now(),
            ]);
        }

        $response = $this->register($this->admin)->assertOk();

        $response->assertDontSee('Previous attendance', false);
        $response->assertDontSee('No previous attendance records.', false);
        $response->assertDontSee('Recent records', false);
        // The payload no longer carries what the block read.
        $response->assertDontSee('"history"', false);
    }

    public function test_the_empty_state_line_is_gone_for_a_student_with_no_history(): void
    {
        $this->register($this->admin)->assertOk()
            ->assertDontSee('No previous attendance records.', false)
            ->assertDontSee('Previous attendance', false);
    }

    /* ---------------------------- What must remain --------------------------- */

    public function test_the_modal_still_offers_status_and_arrival(): void
    {
        $this->register($this->admin)->assertOk()
            ->assertSee('mark-status', false)
            ->assertSee('mark-arrived', false);
    }

    public function test_an_instructor_can_still_mark_a_student(): void
    {
        // Before the academy's late cutoff, or `present` is refused — which is
        // the existing rule, not this change. The next test covers what
        // happens after it.
        $this->travelTo(today()->setTime(9, 0));

        $this->actingAs($this->instructor)
            ->post(route('admin.attendance.daily.mark'), [
                'user_id' => $this->student->id,
                'date' => today()->toDateString(),
                'status' => 'present',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('present', DailyAttendance::sole()->status);
    }

    public function test_an_instructor_can_still_mark_late_and_leave(): void
    {
        // Deliberately after the cutoff: late and leave are always available,
        // and this is the path an instructor takes for the rest of the day.
        $this->travelTo(today()->setTime(14, 0));

        foreach (['late', 'leave'] as $status) {
            $student = User::factory()->create();
            $student->assignRole('student');
            Enrollment::create([
                'user_id' => $student->id,
                'course_id' => Course::sole()->id,
                'enrolled_at' => now()->subYear(),
            ]);

            $this->actingAs($this->instructor)
                ->post(route('admin.attendance.daily.mark'), [
                    'user_id' => $student->id,
                    'date' => today()->toDateString(),
                    'status' => $status,
                ])
                ->assertRedirect()
                ->assertSessionHas('success');

            $this->assertSame($status, DailyAttendance::where('user_id', $student->id)->sole()->status);
        }
    }

    public function test_the_present_cutoff_still_bites(): void
    {
        $this->travelTo(today()->setTime(14, 0));

        $this->actingAs($this->instructor)
            ->post(route('admin.attendance.daily.mark'), [
                'user_id' => $this->student->id,
                'date' => today()->toDateString(),
                'status' => 'present',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, DailyAttendance::count());
    }

    /* ------------------------- The absent lock, untouched -------------------- */

    public function test_a_locked_absence_still_shows_the_chip_and_no_trigger(): void
    {
        DailyAttendance::create([
            'user_id' => $this->student->id,
            'date' => today()->toDateString(),
            'status' => 'absent',
            'source' => 'auto',
            'marked_at' => now(),
        ]);

        $this->register($this->instructor)->assertOk()
            ->assertSee('data-absence-locked', false)
            ->assertDontSee('data-correction-trigger', false)
            ->assertSee('Absent is final. Ask an admin to correct it.', false);
    }

    public function test_a_locked_absence_still_refuses_a_posted_correction(): void
    {
        $record = DailyAttendance::create([
            'user_id' => $this->student->id,
            'date' => today()->toDateString(),
            'status' => 'absent',
            'source' => 'auto',
            'marked_at' => now(),
        ]);

        $this->actingAs($this->instructor)
            ->put(route('admin.attendance.daily.update', $record), [
                'pin' => '1234',
                'status' => 'present',
                'reason' => 'Still refused.',
            ])
            ->assertForbidden();

        $this->assertSame('absent', $record->fresh()->status);
    }

    public function test_the_admin_correction_dialog_is_untouched(): void
    {
        DailyAttendance::create([
            'user_id' => $this->student->id,
            'date' => today()->toDateString(),
            'status' => 'present',
            'source' => 'manual',
            'marked_at' => now(),
        ]);

        $this->register($this->admin)->assertOk()
            ->assertSee('Security PIN', false)
            ->assertSee('data-correction-trigger', false)
            ->assertSee('update-reason', false);
    }

    /* --------------------------- No orphaned backend ------------------------- */

    /**
     * The block's data was two queries per page load. Deleting the markup and
     * leaving them running is the failure this guards: a page that still pays
     * for something nobody sees.
     */
    public function test_no_controller_code_is_left_computing_the_removed_block(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/DailyAttendanceController.php'));

        $this->assertStringNotContainsString('historyFor', $controller);
        $this->assertStringNotContainsString("'history'", $controller);

        $view = file_get_contents(resource_path('views/admin/attendance/daily.blade.php'));

        $this->assertStringNotContainsString('$studentHistory', $view);
        $this->assertStringNotContainsString('history.recent', $view);
        $this->assertStringNotContainsString('history.total', $view);
    }
}
