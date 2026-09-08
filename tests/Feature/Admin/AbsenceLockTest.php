<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Course;
use App\Models\DailyAttendance;
use App\Models\Enrollment;
use App\Models\LeaveApplication;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * An absence can only be undone by someone allowed to undo one.
 *
 * 459f3cc put that rule in the two controller methods that wrote the register
 * then. A later one — releasing a closed absence when a leave is approved
 * late — never called it, and once instructors could review leave that became
 * a way for someone without `attendance.correct-absent` to wipe a billable
 * absence and have the fine credited back.
 *
 * The rule is on the model now, so these test the wall as well as the doors.
 */
class AbsenceLockTest extends TestCase
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
        Notification::fake();

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
            'published_at' => now()->subDay(),
            'is_free' => true,
            'category_id' => $category->id,
            'instructor_id' => $this->instructor->id,
        ]);

        $this->student = User::factory()->create(['name' => 'Absent Student']);
        $this->student->assignRole('student');
        Enrollment::create([
            'user_id' => $this->student->id,
            'course_id' => $course->id,
            'enrolled_at' => now()->subMonth(),
        ]);
    }

    protected function absenceOn(string $date): DailyAttendance
    {
        return DailyAttendance::create([
            'user_id' => $this->student->id,
            'date' => $date,
            'status' => 'absent',
            'source' => 'auto',
            'marked_at' => now(),
        ]);
    }

    protected function leaveFor(string $from, string $to): LeaveApplication
    {
        $leave = LeaveApplication::create([
            'user_id' => $this->student->id,
            'from_date' => $from,
            'to_date' => $to,
            'reason' => 'Family matter',
        ]);
        $leave->openDecisions();

        return $leave->fresh();
    }

    /* --------------------------- The reported hole -------------------------- */

    public function test_an_instructor_approving_leave_cannot_undo_a_closed_absence(): void
    {
        $yesterday = today()->subDay()->toDateString();
        $record = $this->absenceOn($yesterday);
        $leave = $this->leaveFor($yesterday, $yesterday);

        $this->assertFalse($this->instructor->can('attendance.correct-absent'));

        $response = $this->actingAs($this->instructor)
            ->post(route('admin.leaves.review', $leave), ['days' => [$yesterday]])
            ->assertRedirect();

        // The absence stands.
        $this->assertSame('absent', $record->fresh()->status);

        // But the leave decision is still recorded — refusing the whole review
        // would stop an instructor doing the job they were given.
        $this->assertSame('approved', $leave->fresh()->status);

        // And they are told what was left, rather than it failing silently.
        $response->assertSessionHas('success', fn (string $message) => str_contains($message, 'an admin has to release'));
    }

    public function test_an_admin_approving_the_same_leave_does_release_it(): void
    {
        $yesterday = today()->subDay()->toDateString();
        $record = $this->absenceOn($yesterday);
        $leave = $this->leaveFor($yesterday, $yesterday);

        $this->actingAs($this->admin)
            ->post(route('admin.leaves.review', $leave), ['days' => [$yesterday]])
            ->assertRedirect();

        // The legitimate case still works: applied in time, approved late.
        $this->assertSame('leave', $record->fresh()->status);
    }

    /* ------------------------------- The wall ------------------------------- */

    public function test_the_model_refuses_an_unpermitted_undo_whatever_calls_it(): void
    {
        $record = $this->absenceOn(today()->toDateString());

        // Not through a controller at all: this is the guard a fifth caller
        // cannot forget to ask for.
        $this->actingAs($this->instructor);

        $this->expectException(AuthorizationException::class);
        $record->update(['status' => 'present']);
    }

    public function test_the_model_allows_an_undo_by_someone_permitted(): void
    {
        $record = $this->absenceOn(today()->toDateString());

        $this->actingAs($this->admin);
        $record->update(['status' => 'present']);

        $this->assertSame('present', $record->fresh()->status);
    }

    public function test_the_lock_only_bites_when_an_absence_is_actually_changing(): void
    {
        $record = $this->absenceOn(today()->toDateString());
        $this->actingAs($this->instructor);

        // Editing a remark on an absence is not undoing it.
        $record->update(['remarks' => 'Called in.']);
        $this->assertSame('Called in.', $record->fresh()->remarks);

        // Nor is writing `absent` over `absent`.
        $record->update(['status' => 'absent', 'remarks' => 'Still absent.']);
        $this->assertSame('absent', $record->fresh()->status);

        // And a row that was never an absence is freely editable.
        $late = DailyAttendance::create([
            'user_id' => $this->student->id,
            'date' => today()->subDays(2)->toDateString(),
            'status' => 'late',
            'source' => 'manual',
            'marked_at' => now(),
        ]);
        $late->update(['status' => 'present']);
        $this->assertSame('present', $late->fresh()->status);
    }

    public function test_the_system_itself_is_not_blocked(): void
    {
        // No authenticated user: the nightly close and the fine run are not a
        // person working around the lock. The close never revisits a settled
        // day, but the guard must not be able to wedge a console command.
        $record = $this->absenceOn(today()->toDateString());

        $this->assertNull(auth()->user());
        $record->update(['status' => 'leave']);

        $this->assertSame('leave', $record->fresh()->status);
    }

    /* ---------------------------- The other doors --------------------------- */

    public function test_bulk_present_leaves_a_recorded_absence_alone(): void
    {
        Carbon::setTestNow(today()->setTime(9, 5));
        $record = $this->absenceOn(today()->toDateString());

        $this->actingAs($this->instructor)
            ->post(route('admin.attendance.daily.bulk'), ['date' => today()->toDateString()])
            ->assertRedirect();

        $this->assertSame('absent', $record->fresh()->status);
        Carbon::setTestNow();
    }

    public function test_the_pin_gated_correction_still_refuses_an_instructor(): void
    {
        $record = $this->absenceOn(today()->toDateString());
        \App\Support\AttendanceConfig::setEditPin('1234');
        \App\Models\Setting::forgetCached();

        $this->actingAs($this->instructor)
            ->put(route('admin.attendance.daily.update', $record), [
                'pin' => '1234',
                'status' => 'present',
                'reason' => 'They were here.',
            ])
            ->assertForbidden();

        $this->assertSame('absent', $record->fresh()->status);
    }

    public function test_no_new_mass_update_slips_past_the_model_guard(): void
    {
        // A query-builder update fires no model events, so the lock on the
        // model cannot see it. One site does this today and it only writes
        // rows it has already filtered to `pending`. This fails when another
        // appears, so the gap stays known instead of becoming the next
        // forgotten call site.
        $known = ['app/Console/Commands/CloseAttendanceDay.php'];
        $found = [];

        foreach ((new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()))) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Comments stripped first: this file and the trait both describe
            // the pattern in prose, and a guard that trips on its own
            // explanation is a guard people delete.
            $code = preg_replace(
                ['#/\*[\s\S]*?\*/#', '#^\s*//.*$#m'],
                ' ',
                file_get_contents($file->getPathname()),
            );

            // A chain that starts at the class and ends in ->update( is a
            // builder write; an instance write reads `$record->update(`.
            if (preg_match('/DailyAttendance::[^;]{0,400}->update\(/s', $code)) {
                $found[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        sort($found);
        $this->assertSame(
            $known,
            $found,
            'A mass update on daily_attendance_records bypasses the model lock. '
                .'Either write through a model instance, or check mayUndoAbsence() first and add the file here.',
        );
    }

    public function test_the_close_does_not_revisit_a_settled_absence(): void
    {
        $record = $this->absenceOn(today()->toDateString());

        $this->artisan('attendance:close-day')->assertSuccessful();

        $this->assertSame('absent', $record->fresh()->status);
        $this->assertSame(1, DailyAttendance::where('user_id', $this->student->id)->count());
    }
}
