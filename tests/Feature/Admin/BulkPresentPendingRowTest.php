<?php

namespace Tests\Feature\Admin;

use App\Models\DailyAttendance;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bulk mark-present, over a day that already has PENDING rows.
 *
 * This is the tenth incident of the date-cast bug and the only one nobody
 * reported: bulkPresent used updateOrCreate with the date among its match
 * keys, so the lookup missed the student's own pending row on SQLite and the
 * insert that followed hit the unique index on (user_id, date). On MySQL the
 * DATE column truncates and it matched, so production was fine and the tests
 * never went near it.
 *
 * It was found by a survey for the pattern, not by anyone remembering the
 * rule — which is the whole argument for the guard that now scans for it.
 *
 * NOTE ON WHAT THESE PROVE. Pinning the casts to date:Y-m-d and normalising
 * the stored values fixed this particular shape: the controller passes a
 * "Y-m-d" STRING, and a string now matches on both drivers. The two tests
 * below therefore pass on the old updateOrCreate as well, and are here as a
 * behaviour regression rather than as proof of the form.
 *
 * The third test is the one that still distinguishes them, because the other
 * half of the problem is untouched by any amount of normalising: a Carbon
 * handed to the query builder binds as "Y-m-d H:i:s" whatever the attribute
 * cast says. `today()` is the most natural thing to reach for, and it is why
 * ScopesToDay remains the only sanctioned form.
 */
class BulkPresentPendingRowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_a_pending_row_is_filled_in_rather_than_duplicated(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        $student = User::factory()->create(['is_active' => true]);
        $student->assignRole('student');

        // Before the day's late cutoff, so the bulk action will actually mark
        // rather than report everyone as too late — that guard is separate and
        // has its own tests.
        $this->travelTo(today()->setTime(8, 0));
        $day = today()->toDateString();

        // The row the nightly open leaves behind: decided by nobody yet.
        DailyAttendance::create([
            'user_id' => $student->id, 'date' => $day,
            'status' => DailyAttendance::PENDING, 'source' => 'manual', 'marked_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.attendance.daily'))
            ->post(route('admin.attendance.daily.bulk'), ['date' => $day])
            ->assertRedirect();

        $rows = DailyAttendance::where('user_id', $student->id)->get();

        $this->assertCount(1, $rows, 'the pending row was filled in, not duplicated');
        $this->assertSame('present', $rows->first()->status);
    }

    public function test_the_day_still_gets_a_row_for_a_student_with_none(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        $student = User::factory()->create(['is_active' => true]);
        $student->assignRole('student');

        $this->travelTo(today()->setTime(8, 0));
        $day = today()->toDateString();

        $this->actingAs($admin)
            ->from(route('admin.attendance.daily'))
            ->post(route('admin.attendance.daily.bulk'), ['date' => $day])
            ->assertRedirect();

        $this->assertSame(
            'present',
            DailyAttendance::where('user_id', $student->id)->onDate($day)->value('status'),
        );
    }

    /**
     * The half that normalising cannot fix.
     *
     * updateOrCreate given a Carbon binds it with a time, finds nothing, and
     * the insert hits the unique index — measured, not argued. forDay resolves
     * the day itself and looks it up as a range, so it works whatever it is
     * handed.
     */
    public function test_forday_takes_a_carbon_where_update_or_create_cannot(): void
    {
        $student = User::factory()->create(['is_active' => true]);
        $student->assignRole('student');

        $day = today();

        DailyAttendance::create([
            'user_id' => $student->id, 'date' => $day->toDateString(),
            'status' => DailyAttendance::PENDING, 'source' => 'manual', 'marked_at' => now(),
        ]);

        // The form this replaced, handed the most natural argument there is.
        try {
            DailyAttendance::updateOrCreate(
                ['user_id' => $student->id, 'date' => $day],
                ['status' => 'present'],
            );
            $this->fail('updateOrCreate with a Carbon should still miss its own row');
        } catch (\Illuminate\Database\QueryException) {
            // Expected: the lookup missed, the insert hit the unique index.
        }

        // The sanctioned form, same argument.
        DailyAttendance::forDay(['user_id' => $student->id], $day, ['status' => 'present']);

        $this->assertCount(1, DailyAttendance::where('user_id', $student->id)->get());
        $this->assertSame('present', DailyAttendance::where('user_id', $student->id)->value('status'));
    }
}
