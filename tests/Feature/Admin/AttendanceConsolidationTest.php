<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Course;
use App\Models\DailyAttendance;
use App\Models\Enrollment;
use App\Models\User;
use App\Support\AbsenceFine;
use App\Support\ClassAttendanceBackfill;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What the merge had to leave exactly where it was.
 *
 * The class-attendance table and the register recorded overlapping facts, and
 * the student portal had been merging them for display all along. So the test
 * of the consolidation is not that the numbers are right — it is that they are
 * the *same* numbers. Each of these computes a figure from data spread across
 * both tables, runs the backfill, and computes it again.
 */
class AttendanceConsolidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $student;

    protected Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $category = Category::create(['name' => 'Web', 'slug' => 'web-'.Str::random(4)]);
        $instructor = User::factory()->create();
        $instructor->assignRole('instructor');

        $this->course = Course::create([
            'title' => 'A course',
            'slug' => Str::slug('a-course-'.Str::random(4)),
            'excerpt' => 'x',
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now()->subYear(),
            'is_free' => true,
            'category_id' => $category->id,
            'instructor_id' => $instructor->id,
        ]);

        $this->student = User::factory()->create();
        $this->student->assignRole('student');
        Enrollment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'enrolled_at' => now()->subYear(),
        ]);

        $this->createRetiredClassTable();
    }

    /** The table the backfill reads; dropped by the migration after it. */
    protected function createRetiredClassTable(): void
    {
        Schema::create('attendance_records', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_title')->nullable();
            $table->date('date')->index();
            $table->string('status', 10);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('last_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('last_update_reason', 500)->nullable();
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamps();
        });
    }

    protected function classRow(string $date, string $status): void
    {
        DB::table('attendance_records')->insert([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'session_title' => 'Live session',
            'date' => $date,
            'status' => $status,
            'created_at' => $date.' 09:00:00',
            'updated_at' => $date.' 09:00:00',
        ]);
    }

    protected function registerRow(string $date, string $status): DailyAttendance
    {
        return DailyAttendance::create([
            'user_id' => $this->student->id,
            'date' => $date,
            'status' => $status,
            'source' => 'manual',
            'marked_at' => now(),
        ]);
    }

    /**
     * The student's percentage as the portal computed it before the merge:
     * the union of both tables, the register winning any shared day, and an
     * excused class session scored as leave on the way out.
     *
     * Written out here rather than called, because the code that did it is
     * gone — which is the whole point of asserting against it.
     */
    protected function percentAsThePortalUsedToComputeIt(): float
    {
        $days = [];

        foreach (DB::table('attendance_records')->where('user_id', $this->student->id)->get() as $row) {
            $day = \Illuminate\Support\Carbon::parse((string) $row->date)->toDateString();
            $days[$day] ??= $row->status === 'excused' ? 'leave' : $row->status;
        }

        foreach (DailyAttendance::where('user_id', $this->student->id)->decided()->get() as $row) {
            $days[$row->date->toDateString()] = $row->status;
        }

        $counted = collect($days)->countBy()->only(['present', 'late', 'absent', 'leave'])->all();

        return (float) DailyAttendance::weightedPercent($counted);
    }

    /* ---------------------------- The invariants ---------------------------- */

    public function test_the_attendance_percentage_is_identical_after_the_merge(): void
    {
        $this->classRow('2026-06-01', 'present');
        $this->classRow('2026-06-02', 'late');
        $this->classRow('2026-06-03', 'excused');
        $this->classRow('2026-06-04', 'absent');
        // A day both tables answer for: the register wins, before and after.
        $this->classRow('2026-06-05', 'present');
        $this->registerRow('2026-06-05', 'absent');
        $this->registerRow('2026-06-08', 'leave');

        $before = $this->percentAsThePortalUsedToComputeIt();

        ClassAttendanceBackfill::run();

        $after = (float) DailyAttendance::weightedPercent(
            DailyAttendance::where('user_id', $this->student->id)
                ->counted()->get()->countBy('status')->all(),
        );

        // present 100 + late 70 + excused 50 + absent 0 + absent 0 + leave 50,
        // over six days.
        $this->assertSame(45.0, $before);
        $this->assertSame($before, $after);
    }

    public function test_the_student_dashboard_figure_is_identical(): void
    {
        $this->classRow('2026-06-01', 'present');
        $this->classRow('2026-06-02', 'absent');
        $this->registerRow('2026-06-03', 'present');
        $this->registerRow('2026-06-04', 'late');

        // The figure the API serves: present out of every counted day.
        $union = collect();
        foreach (DB::table('attendance_records')->get() as $row) {
            $union->put(\Illuminate\Support\Carbon::parse((string) $row->date)->toDateString(), $row->status);
        }
        foreach (DailyAttendance::decided()->get() as $row) {
            $union->put($row->date->toDateString(), $row->status);
        }
        $before = round($union->filter(fn ($s) => $s === 'present')->count() / $union->count() * 100);

        ClassAttendanceBackfill::run();

        $total = DailyAttendance::where('user_id', $this->student->id)->counted()->count();
        $present = DailyAttendance::where('user_id', $this->student->id)->where('status', 'present')->count();
        $after = round($present / $total * 100);

        $this->assertSame(50.0, $before);
        $this->assertSame($before, $after);
    }

    /**
     * Fines read the register and always did — the class sheet never fed a
     * charge. So the number that must not move is the one computed from the
     * rows that were already there, and the backfill must not add to it.
     */
    public function test_the_absence_fine_total_for_a_month_is_identical(): void
    {
        $month = \Illuminate\Support\Carbon::parse('2026-06-01');

        $this->registerRow('2026-06-01', 'absent');
        $this->registerRow('2026-06-02', 'absent');
        $this->registerRow('2026-06-03', 'absent');

        // Class sessions in the same month, one of them an absence. None of
        // these were ever billable and none may become billable.
        $this->classRow('2026-06-10', 'absent');
        $this->classRow('2026-06-11', 'present');
        $this->classRow('2026-06-12', 'excused');

        $before = AbsenceFine::balance($this->student->id, $month);

        ClassAttendanceBackfill::run();

        $after = AbsenceFine::balance($this->student->id, $month);

        $this->assertSame(3, $before['used']);
        $this->assertSame(
            $before['fine_total'],
            $after['fine_total'],
            'A class session that was never billable must not become a charge by moving table.',
        );
        $this->assertSame($before['used'], $after['used']);
    }

    /**
     * A moved class absence is recorded but not charged.
     *
     * The first draft of this let it become billable, and the fine test above
     * went red — a student would have been charged, for a month already
     * closed, because a table moved underneath them. The absence is real and
     * the register keeps it; the charge is not, and AbsenceFine skips the rows
     * the backfill wrote. Going forward nothing writes that source, so the
     * exception ages out on its own.
     */
    public function test_a_moved_class_absence_is_recorded_but_never_charged(): void
    {
        $month = \Illuminate\Support\Carbon::parse('2026-06-01');
        $this->classRow('2026-06-10', 'absent');

        $this->assertSame(0, AbsenceFine::balance($this->student->id, $month)['used']);

        ClassAttendanceBackfill::run();

        $row = DailyAttendance::where('user_id', $this->student->id)->onDate('2026-06-10')->sole();
        $this->assertSame('absent', $row->status, 'The absence itself is not thrown away.');
        $this->assertSame(0, AbsenceFine::balance($this->student->id, $month)['used']);
    }

    public function test_an_absence_marked_after_the_merge_is_billable_as_always(): void
    {
        $month = \Illuminate\Support\Carbon::parse('2026-06-01');
        ClassAttendanceBackfill::run();

        $this->registerRow('2026-06-10', 'absent');

        $this->assertSame(
            1,
            AbsenceFine::balance($this->student->id, $month)['used'],
            'The grandfathering covers the rows the backfill wrote, not the register itself.',
        );
    }

    /**
     * Nothing outside the one-time migration still reads the retired table.
     *
     * A grep, deliberately: the table is dropped, so a reference to it would
     * only surface as a runtime error on whichever screen still held one, and
     * the point is to find it here instead.
     */
    public function test_no_live_code_path_references_the_retired_table(): void
    {
        // The backfill reads it by name — that is its whole job, it runs once,
        // and the migration that drops the table runs after it.
        $allowed = ['app/Support/ClassAttendanceBackfill.php'];
        $found = [];

        foreach ([app_path(), base_path('routes'), resource_path('views')] as $root) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
                if (! in_array($file->getExtension(), ['php'], true)) {
                    continue;
                }

                // Comments stripped: several files explain the merge in prose,
                // and a guard that trips on its own history is one people
                // delete rather than heed.
                $code = preg_replace(
                    ['#/\*[\s\S]*?\*/#', '#^\s*//.*$#m', '#\{\{--[\s\S]*?--\}\}#'],
                    ' ',
                    file_get_contents($file->getPathname()),
                );

                if (preg_match('/\bAttendanceRecord\b|[\'"]attendance_records[\'"]/', $code)) {
                    $found[] = str_replace(base_path().'/', '', $file->getPathname());
                }
            }
        }

        sort($found);
        $this->assertSame(
            $allowed,
            $found,
            'The class-attendance table is gone. Anything still naming it will fail at runtime.',
        );
    }

    /**
     * A status the day-count list forgot became an unmarked student.
     *
     * dayCounts() and historyFor() each listed the four statuses by hand, so
     * `excused` — which arrived with the class sheet — was subtracted from
     * nothing and fell into `unmarked`, telling the front desk to chase a
     * student who was already marked. Both derive from STATUSES now.
     */
    public function test_an_excused_student_is_not_reported_as_unmarked(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->academyOpensEveryDay();

        DailyAttendance::create([
            'user_id' => $this->student->id,
            'date' => today()->toDateString(),
            'status' => 'excused',
            'source' => 'manual',
            'marked_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.attendance.daily', ['date' => today()->toDateString()]))
            ->assertOk();

        $counts = $response->viewData('counts');

        $this->assertSame(1, $counts['excused']);
        $this->assertSame(0, $counts['unmarked'], 'A marked student is not waiting on anyone.');
        // Worth the same half day as approved leave, so a lone excused day is
        // a 50% register — not a 0% one, and not an undefined one.
        $this->assertSame(50.0, $counts['weighted_percent']);
    }

    public function test_no_class_absence_escapes_the_lock_by_moving(): void
    {
        $this->classRow('2026-06-10', 'absent');
        ClassAttendanceBackfill::run();

        $row = DailyAttendance::where('user_id', $this->student->id)->onDate('2026-06-10')->sole();
        $instructor = User::factory()->create();
        $instructor->assignRole('instructor');
        $this->actingAs($instructor);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $row->update(['status' => 'present']);
    }
}
