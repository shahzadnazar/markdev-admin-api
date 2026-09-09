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
 * The register does not offer a correction it will refuse.
 *
 * An instructor could open the PIN dialog on an `absent` row, type a PIN, and
 * be told to ask an admin — an invitation to an action that could never
 * succeed. The control is hidden for anyone without `attendance.correct-absent`
 * now, and the row says why instead.
 *
 * This is presentation only, which is the point of the last test in here: the
 * model guard and the controller check are what actually stop the write, and
 * they answer a hand-made POST exactly as they did when the button existed.
 * A hidden button is not a rule.
 */
class AbsenceCorrectionAffordanceTest extends TestCase
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
            'published_at' => now()->subDay(),
            'is_free' => true,
            'category_id' => $category->id,
            'instructor_id' => $this->instructor->id,
        ]);

        $this->student = User::factory()->create(['name' => 'Locked Student']);
        $this->student->assignRole('student');
        Enrollment::create([
            'user_id' => $this->student->id,
            'course_id' => $course->id,
            'enrolled_at' => now()->subMonth(),
        ]);
    }

    protected function mark(string $status): DailyAttendance
    {
        return DailyAttendance::create([
            'user_id' => $this->student->id,
            'date' => today()->toDateString(),
            'status' => $status,
            'source' => 'manual',
            'marked_at' => now(),
        ]);
    }

    protected function register(User $as): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as)
            ->get(route('admin.attendance.daily', ['date' => today()->toDateString()]));
    }

    /* ------------------------------ The register ----------------------------- */

    public function test_an_instructor_sees_no_correction_control_on_an_absent_row(): void
    {
        $this->mark('absent');

        $response = $this->register($this->instructor)->assertOk();

        // The pencil is the only way into the correction dialog. Asserted on
        // its own marker rather than on `openUpdate(`, which also appears in
        // the Alpine component's own definition further down the page and so
        // would pass whether the button were there or not.
        $response->assertDontSee('data-correction-trigger', false);
        $response->assertSee('data-absence-locked', false);
        $response->assertSee('Absent is final. Ask an admin to correct it.', false);
    }

    public function test_an_instructor_still_gets_the_normal_control_on_a_present_row(): void
    {
        $this->mark('present');

        $response = $this->register($this->instructor)->assertOk();

        $response->assertSee('data-correction-trigger', false);
        $response->assertDontSee('data-absence-locked', false);
    }

    public function test_an_admin_sees_the_pin_dialog_on_an_absent_row_exactly_as_before(): void
    {
        $this->mark('absent');

        $response = $this->register($this->admin)->assertOk();

        $response->assertSee('data-correction-trigger', false);
        $response->assertSee('Security PIN', false);
        $response->assertDontSee('data-absence-locked', false);
        // And the payload the dialog reads says they may undo one, which is
        // what keeps the PIN field in the dialog rather than the refusal.
        $response->assertSee('"may_undo_absence":true', false);
    }

    /**
     * The dialog's own refusal notice is still wired for an instructor.
     *
     * A locked row emits no payload at all now — the trigger is what carried
     * it — so this checks a row they *can* open: the flag it reads still says
     * they may not undo an absence, which is what keeps the in-dialog notice
     * and the hidden PIN field working if a row ever became absent while the
     * dialog was open.
     */
    public function test_the_instructor_payload_still_says_they_may_not_undo_an_absence(): void
    {
        $this->mark('present');

        $this->register($this->instructor)->assertOk()
            ->assertSee('"may_undo_absence":false', false);
    }

    /* --------------------------- The rule, unmoved --------------------------- */

    /**
     * The important one. The button is gone; the refusal is not.
     */
    public function test_an_instructor_posting_a_correction_anyway_is_still_refused(): void
    {
        $record = $this->mark('absent');

        $this->actingAs($this->instructor)
            ->put(route('admin.attendance.daily.update', $record), [
                'pin' => '1234',
                'status' => 'present',
                'reason' => 'Trying it without the button.',
            ])
            ->assertForbidden();

        $this->assertSame('absent', $record->fresh()->status);
    }

    public function test_the_model_guard_is_untouched(): void
    {
        $record = $this->mark('absent');
        $this->actingAs($this->instructor);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $record->update(['status' => 'present']);
    }

    /* ------------------------- What decides the display ---------------------- */

    public function test_the_view_asks_the_same_question_the_guard_does(): void
    {
        $absent = $this->mark('absent');

        $this->actingAs($this->instructor);
        $this->assertTrue($absent->isLockedAbsence());

        $this->actingAs($this->admin);
        $this->assertFalse($absent->isLockedAbsence());

        // Never locked when it is not an absence, whoever is looking.
        $absent->update(['status' => 'absent']);
        $present = DailyAttendance::create([
            'user_id' => User::factory()->create()->id,
            'date' => today()->toDateString(),
            'status' => 'present',
            'source' => 'manual',
            'marked_at' => now(),
        ]);
        $this->actingAs($this->instructor);
        $this->assertFalse($present->isLockedAbsence());
    }
}
