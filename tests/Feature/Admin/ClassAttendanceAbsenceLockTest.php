<?php

namespace Tests\Feature\Admin;

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The absent lock, on the *other* attendance table.
 *
 * e58ec3c locked `daily_attendance_records` and audited the class sheet as
 * read-only on the strength of Api\V1\AttendanceController, missing
 * Admin\AttendanceController::save() — which upserts `attendance_records`.
 * An instructor could open Learning → Attendance, flip a student from absent
 * to present, and be told "Attendance saved for 1 student(s)" while the
 * daily register kept its absence. Every absent-lock test was green the
 * whole time this was open, which is why these exist separately.
 */
class ClassAttendanceAbsenceLockTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $instructor;

    protected User $student;

    protected Course $course;

    protected string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->date = today()->toDateString();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $category = Category::create(['name' => 'Web', 'slug' => 'web-'.Str::random(4)]);

        $this->instructor = User::factory()->create();
        $this->instructor->assignRole('instructor');

        $this->course = Course::create([
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
            'course_id' => $this->course->id,
            'enrolled_at' => now()->subMonth(),
        ]);
    }

    protected function absence(): AttendanceRecord
    {
        return AttendanceRecord::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'date' => $this->date,
            'status' => 'absent',
            'notes' => 'No show',
        ]);
    }

    /** @param array<string, mixed> $extra */
    protected function sheet(string $status, array $extra = []): array
    {
        return array_merge([
            'course_id' => $this->course->id,
            'date' => $this->date,
            'rows' => [['user_id' => $this->student->id, 'status' => $status]],
        ], $extra);
    }

    /* ---------------------------- The reported hole --------------------------- */

    public function test_an_instructor_cannot_turn_a_class_absence_into_present(): void
    {
        $record = $this->absence();

        $this->actingAs($this->instructor)
            ->post(route('admin.attendance.save'), $this->sheet('present'))
            ->assertForbidden();

        $this->assertSame('absent', $record->fresh()->status);
    }

    public function test_an_instructor_cannot_turn_a_class_absence_into_late_either(): void
    {
        $record = $this->absence();

        $this->actingAs($this->instructor)
            ->post(route('admin.attendance.save'), $this->sheet('late'))
            ->assertForbidden();

        $this->assertSame('absent', $record->fresh()->status);
    }

    public function test_the_refusal_says_why_instead_of_claiming_the_sheet_saved(): void
    {
        $this->absence();

        $response = $this->actingAs($this->instructor)
            ->post(route('admin.attendance.save'), $this->sheet('present'));

        $response->assertForbidden();
        $response->assertSee('Absent is final. Ask an admin to correct it.', false);
        $this->assertNull(session('success'));
    }

    /* ------------------------ What must keep working ------------------------ */

    public function test_an_instructor_marks_an_unmarked_student_normally(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('admin.attendance.save'), $this->sheet('present'))
            ->assertRedirect()
            ->assertSessionHas('success', 'Attendance saved for 1 student(s).');

        $this->assertSame('present', AttendanceRecord::sole()->status);
    }

    public function test_an_instructor_may_still_mark_a_present_student_absent(): void
    {
        $record = AttendanceRecord::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'date' => $this->date,
            'status' => 'present',
        ]);

        $this->actingAs($this->instructor)
            ->post(route('admin.attendance.save'), $this->sheet('absent'))
            ->assertRedirect();

        $this->assertSame('absent', $record->fresh()->status);
    }

    /**
     * The bug underneath the bug: `updateOrCreate(['date' => $date])` compares
     * a bare date string against a date-cast column, which misses on any store
     * that keeps the time part — so every save appended a second row for the
     * same day instead of correcting the first, and the guard could never fire.
     */
    public function test_saving_the_same_day_twice_corrects_the_row_rather_than_adding_one(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('admin.attendance.save'), $this->sheet('present'));
        $this->actingAs($this->instructor)
            ->post(route('admin.attendance.save'), $this->sheet('late'));

        $this->assertSame(1, AttendanceRecord::count());
        $this->assertSame('late', AttendanceRecord::sole()->status);
    }

    /* ---------------------------- The admin escape --------------------------- */

    public function test_an_admin_may_correct_a_class_absence_with_a_reason(): void
    {
        $record = $this->absence();

        $this->actingAs($this->admin)
            ->post(route('admin.attendance.save'), $this->sheet('present', [
                'reason' => 'Signed in at reception; the sheet was marked before they arrived.',
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $record->refresh();
        $this->assertSame('present', $record->status);
        $this->assertSame($this->admin->id, $record->last_updated_by);
        $this->assertSame('Signed in at reception; the sheet was marked before they arrived.', $record->last_update_reason);
        $this->assertNotNull($record->last_updated_at);
    }

    public function test_that_correction_is_audited_with_the_reason_and_the_old_status(): void
    {
        $this->absence();

        $this->actingAs($this->admin)->post(route('admin.attendance.save'), $this->sheet('present', [
            'reason' => 'Reception log shows they arrived.',
        ]));

        $entry = AuditLog::where('action', 'attendance_corrected')
            ->where('module', 'attendance_records')
            ->sole();

        $this->assertSame('absent', $entry->old_values['status']);
        $this->assertSame('present', $entry->new_values['status']);
        $this->assertSame('Reception log shows they arrived.', $entry->new_values['reason']);
        $this->assertSame($this->admin->id, $entry->user_id);
    }

    public function test_an_admin_correcting_an_absence_with_no_reason_is_refused(): void
    {
        $record = $this->absence();

        $this->actingAs($this->admin)
            ->post(route('admin.attendance.save'), $this->sheet('present'))
            ->assertRedirect()
            ->assertSessionHas('error')
            ->assertSessionMissing('success');

        $this->assertSame('absent', $record->fresh()->status);
    }

    public function test_an_admin_marking_an_unmarked_student_needs_no_reason(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.attendance.save'), $this->sheet('present'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('present', AttendanceRecord::sole()->status);
    }

    /**
     * All or nothing. A sheet carrying one locked row must not half-apply: the
     * instructor would then be told nothing while some of their marks landed.
     */
    public function test_a_refused_sheet_writes_none_of_its_other_rows(): void
    {
        $this->absence();

        $classmate = User::factory()->create();
        $classmate->assignRole('student');
        Enrollment::create([
            'user_id' => $classmate->id,
            'course_id' => $this->course->id,
            'enrolled_at' => now()->subMonth(),
        ]);

        $this->actingAs($this->instructor)
            ->post(route('admin.attendance.save'), [
                'course_id' => $this->course->id,
                'date' => $this->date,
                'rows' => [
                    ['user_id' => $this->student->id, 'status' => 'present'],
                    ['user_id' => $classmate->id, 'status' => 'present'],
                ],
            ])
            ->assertForbidden();

        $this->assertSame(0, AttendanceRecord::where('user_id', $classmate->id)->count());
    }

    /* --------------------------- Scoping still holds -------------------------- */

    /**
     * save() was restructured for the lock, so its course check is re-asserted
     * here: the sheet had no test of its own before this file existed, which
     * is the same absence of cover that let the absent hole sit open.
     */
    public function test_an_instructor_still_cannot_save_another_instructor_s_sheet(): void
    {
        $other = User::factory()->create();
        $other->assignRole('instructor');

        $this->actingAs($other)
            ->post(route('admin.attendance.save'), $this->sheet('present'))
            ->assertForbidden();

        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_an_instructor_only_sees_their_own_courses_on_the_sheet(): void
    {
        $other = User::factory()->create();
        $other->assignRole('instructor');

        $this->actingAs($other)
            ->get(route('admin.attendance.index', ['course' => $this->course->id, 'date' => $this->date]))
            ->assertForbidden();

        $this->actingAs($this->instructor)
            ->get(route('admin.attendance.index', ['course' => $this->course->id, 'date' => $this->date]))
            ->assertOk()
            ->assertSee($this->course->title);
    }

    /* ------------------------------- The wall ------------------------------- */

    public function test_the_model_refuses_an_unpermitted_undo_whatever_calls_it(): void
    {
        $record = $this->absence();

        $this->actingAs($this->instructor);

        $this->expectException(AuthorizationException::class);
        $record->update(['status' => 'present']);
    }

    public function test_update_or_create_fires_the_guard_when_its_lookup_matches(): void
    {
        $record = $this->absence();
        $this->actingAs($this->instructor);

        $this->expectException(AuthorizationException::class);

        // updateOrCreate is firstOrNew()->fill()->save() underneath — an
        // instance save, so `updating` fires and the lock sees it. The date is
        // passed as the model's own attribute here so the lookup certainly
        // matches; the next test is about what happens when it does not.
        AttendanceRecord::updateOrCreate(
            ['user_id' => $this->student->id, 'course_id' => $this->course->id, 'date' => $record->date],
            ['status' => 'present'],
        );
    }

    /**
     * Why save() no longer calls updateOrCreate with a raw date string.
     *
     * The column is date-cast, so it is written as "Y-m-d H:i:s" — truncated
     * by a real MySQL DATE column but stored verbatim by SQLite. The lookup
     * `['date' => '2026-09-08']` therefore matches on MySQL and misses on
     * SQLite, and the two failures are different bugs from the same line: on
     * MySQL the absence was silently overwritten, and on SQLite a second row
     * for the same day was appended beside it. This is the seventh time a
     * date-cast comparison has bitten this codebase; the controller reads
     * through onDate() now, and this test is here so the trap stays visible
     * rather than being reintroduced as a "simplification".
     */
    public function test_a_bare_date_string_is_not_a_reliable_lookup_on_a_date_cast_column(): void
    {
        $this->absence();

        $stored = (string) \Illuminate\Support\Facades\DB::table('attendance_records')->value('date');
        $bare = AttendanceRecord::where('date', $this->date)->exists();

        // The raw value carries a time part on a store that keeps one (SQLite)
        // and not on one that truncates to a real DATE (MySQL). A bare
        // equality therefore finds the row on one and misses it on the other,
        // from the very same line of code — which is how the class sheet
        // managed to overwrite an absence in production while appending a
        // duplicate row in the test suite.
        $this->assertSame($stored === $this->date, $bare);

        // onDate() is right on both, which is why the controller uses it.
        $this->assertTrue(AttendanceRecord::onDate($this->date)->exists());
    }

    public function test_the_system_itself_is_not_blocked(): void
    {
        $record = $this->absence();

        // Nobody authenticated — the nightly close, a console command, a job.
        $record->update(['status' => 'excused']);

        $this->assertSame('excused', $record->fresh()->status);
    }

    /* ------------------- Neither table's rule bleeds into the other ------------------ */

    /**
     * Fines are charged off the daily register and nothing else, so nothing
     * here can change what a student is billed. The two tables share the word
     * "absent" and no key, and the class sheet has never fed a charge.
     */
    public function test_correcting_a_class_absence_does_not_touch_the_register_or_a_fine(): void
    {
        $this->absence();

        $month = today()->copy()->startOfMonth();
        \App\Models\DailyAttendance::create([
            'user_id' => $this->student->id,
            'date' => $this->date,
            'status' => 'absent',
            'source' => 'auto',
            'marked_at' => now(),
        ]);

        $before = \App\Support\AbsenceFine::absencesIn($this->student->id, $month);

        $this->actingAs($this->admin)->post(route('admin.attendance.save'), $this->sheet('present', [
            'reason' => 'Reception log shows they arrived.',
        ]));

        $this->assertSame(1, $before);
        $this->assertSame($before, \App\Support\AbsenceFine::absencesIn($this->student->id, $month));
        $this->assertSame(
            'absent',
            \App\Models\DailyAttendance::where('user_id', $this->student->id)->onDate($this->date)->sole()->status,
            'The register keeps its absence — the class sheet is a different record, not a second opinion on this one.',
        );
    }


    public function test_the_biometric_service_never_downgrades_an_existing_class_record(): void
    {
        // The only other writer of attendance_records creates and never
        // updates, which is what keeps it out of this lock's way.
        $source = file_get_contents(app_path('Services/BiometricAttendanceService.php'));

        $this->assertStringNotContainsString('AttendanceRecord::updateOrCreate', $source);
        $this->assertStringContainsString('Never downgrade a manual or earlier-punch status.', $source);
    }
}
