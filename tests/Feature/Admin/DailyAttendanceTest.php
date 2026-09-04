<?php

namespace Tests\Feature\Admin;

use App\Models\Course;
use App\Models\DailyAttendance;
use App\Models\Enrollment;
use App\Models\User;
use App\Support\AttendanceConfig;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class DailyAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        RateLimiter::clear('attendance-pin:'.$this->admin->id);

        $this->student = User::factory()->create(['name' => 'Aliya Khan']);
        $this->student->assignRole('student');

        AttendanceConfig::setEditPin('4321');

        // Present is only markable up to the day start plus its grace, so
        // these run at a moment when it still is. The cutoff tests below move
        // the slot rather than the clock.
        $this->travelTo(today()->setTime(9, 0));
    }

    protected function mark(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->post('/admin/attendance/daily', array_merge([
            'user_id' => $this->student->id,
            'date' => today()->toDateString(),
            'status' => 'present',
        ], $overrides));
    }

    public function test_register_lists_active_students_as_unmarked(): void
    {
        $inactive = User::factory()->create(['name' => 'Gone Student', 'is_active' => false]);
        $inactive->assignRole('student');

        $this->actingAs($this->admin)->get('/admin/attendance/daily')
            ->assertOk()
            ->assertSee('Daily attendance')
            ->assertSee('Aliya Khan')
            ->assertSee('Not marked')
            ->assertDontSee('Gone Student');
    }

    public function test_marking_creates_the_record_with_time_and_marker(): void
    {
        $this->mark(['status' => 'late', 'remarks' => 'Traffic'])->assertRedirect();

        $record = DailyAttendance::first();
        $this->assertSame('late', $record->status);
        $this->assertSame('Traffic', $record->remarks);
        $this->assertSame($this->admin->id, $record->marked_by);
        $this->assertNotNull($record->marked_at);
        $this->assertNull($record->last_updated_at);
    }

    public function test_a_day_cannot_be_marked_twice(): void
    {
        $this->mark();
        $this->mark(['status' => 'absent'])->assertSessionHas('error');

        $this->assertSame(1, DailyAttendance::count());
        $this->assertSame('present', DailyAttendance::first()->status);
    }

    public function test_future_dates_cannot_be_marked(): void
    {
        $this->mark(['date' => today()->addDay()->toDateString()])
            ->assertSessionHasErrors('date');
    }

    public function test_update_rejects_a_wrong_pin(): void
    {
        $this->mark();
        $record = DailyAttendance::first();

        $this->actingAs($this->admin)
            ->put("/admin/attendance/daily/{$record->id}", [
                'pin' => '9999',
                'status' => 'absent',
                'reason' => 'Was actually absent',
            ])
            ->assertSessionHasErrors('pin');

        $this->assertSame('present', $record->fresh()->status);
    }

    public function test_update_requires_a_reason(): void
    {
        $this->mark();
        $record = DailyAttendance::first();

        $this->actingAs($this->admin)
            ->put("/admin/attendance/daily/{$record->id}", [
                'pin' => '4321',
                'status' => 'absent',
            ])
            ->assertSessionHasErrors('reason');

        $this->assertSame('present', $record->fresh()->status);
    }

    public function test_update_with_pin_and_reason_corrects_and_logs(): void
    {
        $this->mark();
        $record = DailyAttendance::first();

        $this->actingAs($this->admin)
            ->put("/admin/attendance/daily/{$record->id}", [
                'pin' => '4321',
                'status' => 'leave',
                'remarks' => 'Family emergency',
                'reason' => 'Guardian called in the morning',
            ])
            ->assertSessionHas('success');

        $record->refresh();
        $this->assertSame('leave', $record->status);
        $this->assertSame('Family emergency', $record->remarks);
        $this->assertSame($this->admin->id, $record->last_updated_by);
        $this->assertSame('Guardian called in the morning', $record->last_update_reason);
        $this->assertNotNull($record->last_updated_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'attendance_corrected',
            'module' => 'daily_attendance',
        ]);
    }

    public function test_pin_attempts_are_rate_limited(): void
    {
        $this->mark();
        $record = DailyAttendance::first();

        foreach (range(1, 5) as $i) {
            $this->actingAs($this->admin)->put("/admin/attendance/daily/{$record->id}", [
                'pin' => '0000',
                'status' => 'absent',
                'reason' => 'attempt '.$i,
            ]);
        }

        $this->actingAs($this->admin)
            ->put("/admin/attendance/daily/{$record->id}", [
                'pin' => '4321', // correct, but throttled now
                'status' => 'absent',
                'reason' => 'should be blocked',
            ])
            ->assertSessionHas('error');

        $this->assertSame('present', $record->fresh()->status);
    }

    public function test_bulk_present_marks_only_unmarked_students(): void
    {
        $second = User::factory()->create();
        $second->assignRole('student');

        $this->mark(['status' => 'leave']); // Aliya already marked

        $this->actingAs($this->admin)
            ->post('/admin/attendance/daily/bulk-present?date='.today()->toDateString())
            ->assertSessionHas('success');

        $this->assertSame(2, DailyAttendance::count());
        $this->assertSame('leave', DailyAttendance::where('user_id', $this->student->id)->first()->status);
        $this->assertSame('present', DailyAttendance::where('user_id', $second->id)->first()->status);
    }

    public function test_instructor_cannot_open_the_daily_register(): void
    {
        $instructor = User::factory()->create();
        $instructor->assignRole('instructor');

        $this->actingAs($instructor)->get('/admin/attendance/daily')->assertForbidden();
        $this->actingAs($instructor)->post('/admin/attendance/daily', [
            'user_id' => $this->student->id,
            'date' => today()->toDateString(),
            'status' => 'present',
        ])->assertForbidden();
    }

    public function test_manager_can_mark(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $this->actingAs($manager)->post('/admin/attendance/daily', [
            'user_id' => $this->student->id,
            'date' => today()->toDateString(),
            'status' => 'present',
        ])->assertRedirect();

        $this->assertSame(1, DailyAttendance::count());
    }

    public function test_student_sees_their_daily_history_via_api(): void
    {
        $this->mark(['status' => 'late', 'remarks' => 'Traffic']);

        \Laravel\Sanctum\Sanctum::actingAs($this->student);

        $this->getJson('/api/v1/attendance/daily')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'late')
            ->assertJsonPath('data.0.remarks', 'Traffic')
            ->assertJsonPath('data.0.corrected', false)
            ->assertJsonPath('meta.total', 1);
    }

    protected function courseWithEnrollment(User $student, string $title = 'Laravel Bootcamp'): Course
    {
        $course = Course::create([
            'title' => $title,
            'slug' => \Illuminate\Support\Str::slug($title.'-'.\Illuminate\Support\Str::random(4)),
            'excerpt' => 'x',
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now(),
            'is_free' => true,
        ]);

        Enrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'enrolled_at' => now(),
        ]);

        return $course;
    }

    public function test_course_filter_narrows_the_register(): void
    {
        $enrolled = User::factory()->create(['name' => 'Enrolled Ali']);
        $enrolled->assignRole('student');
        $course = $this->courseWithEnrollment($enrolled);

        $this->actingAs($this->admin)
            ->get('/admin/attendance/daily?course='.$course->id)
            ->assertOk()
            ->assertSee('Enrolled Ali')
            ->assertDontSee('Aliya Khan');
    }

    public function test_register_prints_to_pdf_with_filters(): void
    {
        $this->mark(['status' => 'late']);

        $response = $this->actingAs($this->admin)
            ->get('/admin/attendance/daily/print?date='.today()->toDateString());

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_student_attendance_page_shows_history_with_ranges(): void
    {
        $this->mark(['status' => 'late', 'remarks' => 'Traffic']);
        DailyAttendance::create([
            'user_id' => $this->student->id,
            'date' => today()->subDay()->toDateString(),
            'status' => 'present',
            'source' => 'manual',
            'marked_by' => $this->admin->id,
            'marked_at' => now()->subDay(),
        ]);

        $this->actingAs($this->admin)
            ->get('/admin/attendance/daily/'.$this->student->id.'?range=all')
            ->assertOk()
            ->assertSee('Aliya Khan')
            ->assertSee('Traffic')
            ->assertSee('Attendance history');

        // "Yesterday" excludes today's late record.
        $this->actingAs($this->admin)
            ->get('/admin/attendance/daily/'.$this->student->id.'?range=yesterday')
            ->assertOk()
            ->assertDontSee('Traffic');
    }

    public function test_student_attendance_page_rejects_non_students(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/attendance/daily/'.$this->admin->id)
            ->assertNotFound();
    }

    public function test_student_history_prints_to_pdf(): void
    {
        $this->mark();

        $response = $this->actingAs($this->admin)
            ->get('/admin/attendance/daily/'.$this->student->id.'/print?range=all');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_register_shows_marker_role_and_course_column(): void
    {
        $this->courseWithEnrollment($this->student, 'React Patterns');
        $this->mark();

        $this->actingAs($this->admin)
            ->get('/admin/attendance/daily')
            ->assertOk()
            ->assertSee('React Patterns')
            ->assertSee('Admin'); // marker shown by role
    }

    public function test_manual_mark_stores_arrival_time(): void
    {
        $this->mark(['status' => 'late', 'arrived_at' => '09:40']);

        $record = DailyAttendance::first();
        $this->assertSame('09:40', substr($record->arrived_at, 0, 5));
    }

    public function test_custom_range_filters_between_from_and_to(): void
    {
        foreach ([5, 3, 1] as $back) {
            DailyAttendance::create([
                'user_id' => $this->student->id,
                'date' => today()->subDays($back)->toDateString(),
                'status' => 'present',
                'remarks' => 'day-'.$back,
                'source' => 'manual',
                'marked_by' => $this->admin->id,
                'marked_at' => now(),
            ]);
        }

        $from = today()->subDays(4)->toDateString();
        $to = today()->subDays(2)->toDateString();

        $this->actingAs($this->admin)
            ->get("/admin/attendance/daily/{$this->student->id}?range=custom&from={$from}&to={$to}")
            ->assertOk()
            ->assertSee('day-3')
            ->assertDontSee('day-5')
            ->assertDontSee('day-1');
    }

    /* ------------------------------ Late cutoff ---------------------------- */

    protected function slotStudent(string $start, int $grace): User
    {
        $slot = \App\Models\AttendanceSlot::create([
            'name' => 'Cutoff group '.$start,
            'start_time' => $start,
            'end_time' => '23:30:00',
            'days' => array_keys(\App\Models\AttendanceSlot::DAYS),
            'late_after_minutes' => $grace,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $student = User::factory()->create();
        $student->assignRole('student');
        $student->studentProfile()->create([
            'reg_no' => 'MD-CUT-'.$student->id,
            'attendance_slot_id' => $slot->id,
        ]);

        return $student->fresh();
    }

    public function test_present_is_allowed_before_the_slot_cutoff(): void
    {
        // Starts an hour from now, so the cutoff is still ahead.
        $student = $this->slotStudent(now()->addHour()->format('H:i:s'), 15);

        $this->actingAs($this->admin)->post('/admin/attendance/daily', [
            'user_id' => $student->id,
            'date' => today()->toDateString(),
            'status' => 'present',
        ])->assertSessionHas('success');

        $this->assertSame('present', DailyAttendance::onDate(today())->where('user_id', $student->id)->value('status'));
    }

    public function test_present_is_refused_by_the_controller_after_the_cutoff(): void
    {
        // Started two hours ago with a 15 minute grace — long past.
        $student = $this->slotStudent(now()->subHours(2)->format('H:i:s'), 15);

        $this->actingAs($this->admin)->post('/admin/attendance/daily', [
            'user_id' => $student->id,
            'date' => today()->toDateString(),
            'status' => 'present',
        ])->assertSessionHas('error');

        $this->assertSame(0, DailyAttendance::where('user_id', $student->id)->count());
    }

    public function test_late_is_still_allowed_after_the_cutoff(): void
    {
        $student = $this->slotStudent(now()->subHours(2)->format('H:i:s'), 15);

        $this->actingAs($this->admin)->post('/admin/attendance/daily', [
            'user_id' => $student->id,
            'date' => today()->toDateString(),
            'status' => 'late',
        ])->assertSessionHas('success');

        $this->assertSame('late', DailyAttendance::onDate(today())->where('user_id', $student->id)->value('status'));
    }

    public function test_back_filling_an_earlier_date_is_judged_against_that_day(): void
    {
        // The cutoff belongs to the slot, so any past day's cutoff has passed
        // however early in the day the slot starts.
        $student = $this->slotStudent(now()->addHours(3)->format('H:i:s'), 15);

        $this->actingAs($this->admin)->post('/admin/attendance/daily', [
            'user_id' => $student->id,
            'date' => today()->subDay()->toDateString(),
            'status' => 'present',
        ])->assertSessionHas('error');

        $this->assertSame(0, DailyAttendance::where('user_id', $student->id)->count());
    }

    public function test_bulk_present_skips_students_past_their_cutoff(): void
    {
        $intime = $this->slotStudent(now()->addHour()->format('H:i:s'), 15);
        $late = $this->slotStudent(now()->subHours(2)->format('H:i:s'), 15);

        $this->actingAs($this->admin)->post('/admin/attendance/daily/bulk-present', [
            'date' => today()->toDateString(),
        ])->assertSessionHas('success');

        $this->assertSame('present', DailyAttendance::onDate(today())->where('user_id', $intime->id)->value('status'));
        $this->assertSame(0, DailyAttendance::where('user_id', $late->id)->count());
    }

    public function test_the_register_says_why_present_is_unavailable(): void
    {
        $this->slotStudent(now()->subHours(2)->format('H:i:s'), 15);

        $this->actingAs($this->admin)->get('/admin/attendance/daily')
            ->assertOk()
            ->assertSee('Late cutoff passed');
    }
}
