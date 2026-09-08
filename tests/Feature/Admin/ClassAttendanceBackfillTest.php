<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Course;
use App\Models\DailyAttendance;
use App\Models\User;
use App\Support\ClassAttendanceBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * The merge that retires the class-attendance sheet.
 *
 * These run the real backfill against rows in both tables, including a date
 * both tables answer for, and assert the counts rather than trusting them.
 */
class ClassAttendanceBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected User $student;

    protected Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'Web', 'slug' => 'web-'.Str::random(4)]);
        $instructor = User::factory()->create();

        $this->course = Course::create([
            'title' => 'A course',
            'slug' => Str::slug('a-course-'.Str::random(4)),
            'excerpt' => 'x',
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'is_free' => true,
            'category_id' => $category->id,
            'instructor_id' => $instructor->id,
        ]);

        $this->student = User::factory()->create();

        $this->createRetiredClassTable();
    }

    /**
     * The table the backfill reads, recreated for the test.
     *
     * It is dropped by the migration that follows the backfill, so by the time
     * the suite runs it no longer exists — but the backfill only ever runs on
     * a database that still has it. Same shape the drop migration's own down()
     * restores, so the two cannot drift.
     */
    protected function createRetiredClassTable(): void
    {
        \Illuminate\Support\Facades\Schema::create('attendance_records', function (\Illuminate\Database\Schema\Blueprint $table) {
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

    /** Written through the query builder: the model is about to stop having a table. */
    protected function classRow(string $date, string $status, array $extra = []): void
    {
        DB::table('attendance_records')->insert(array_merge([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'session_title' => 'Live session — '.$date,
            'date' => $date,
            'status' => $status,
            'notes' => 'a note',
            'created_at' => $date.' 09:00:00',
            'updated_at' => $date.' 09:00:00',
        ], $extra));
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

    public function test_every_class_row_moves_and_the_counts_add_up(): void
    {
        $this->classRow('2026-08-03', 'present');
        $this->classRow('2026-08-04', 'late');
        $this->classRow('2026-08-05', 'absent');
        $this->classRow('2026-08-06', 'excused');
        $this->registerRow('2026-08-10', 'present');

        $result = ClassAttendanceBackfill::run();

        $this->assertSame(4, $result['class_rows']);
        $this->assertSame(1, $result['register_before']);
        $this->assertSame(4, $result['inserted']);
        $this->assertSame(0, $result['conflicts']);
        $this->assertSame(5, $result['register_after']);
        $this->assertSame(
            $result['register_before'] + $result['inserted'],
            $result['register_after'],
            'Nothing may be lost or invented in the move.',
        );
    }

    public function test_the_register_wins_where_both_tables_answer_for_a_day(): void
    {
        $kept = $this->registerRow('2026-08-03', 'absent');
        $this->classRow('2026-08-03', 'present');
        $this->classRow('2026-08-04', 'present');

        $result = ClassAttendanceBackfill::run();

        $this->assertSame(1, $result['conflicts']);
        $this->assertSame(['2026-08-03'], $result['conflict_dates']);
        $this->assertSame(1, $result['inserted']);

        // The register's own answer stands — it is the one that drives the
        // fine and carries the correction trail.
        $this->assertSame('absent', $kept->fresh()->status);
        $this->assertSame(2, DailyAttendance::count());
    }

    public function test_course_and_session_title_come_across(): void
    {
        $this->classRow('2026-08-03', 'present');

        ClassAttendanceBackfill::run();

        $row = DailyAttendance::sole();
        $this->assertSame($this->course->id, $row->course_id);
        $this->assertSame('Live session — 2026-08-03', $row->session_title);
        $this->assertSame('a note', $row->remarks);
        $this->assertSame(ClassAttendanceBackfill::SOURCE, $row->source);
        // Not the day of the migration: a historic session keeps its own date.
        $this->assertSame('2026-08-03', $row->marked_at->toDateString());
    }

    public function test_excused_stays_excused_rather_than_becoming_leave(): void
    {
        $this->classRow('2026-08-03', 'excused');

        ClassAttendanceBackfill::run();

        $this->assertSame('excused', DailyAttendance::sole()->status);
        $this->assertSame(0, DailyAttendance::where('status', 'leave')->count());
    }

    public function test_excused_is_worth_what_it_was_worth_before(): void
    {
        // The merged student view relabelled excused as leave on the way out,
        // so it scored 50. The register's own weight has to agree or every
        // percentage would move on the day of the migration.
        $this->assertSame(50, DailyAttendance::WEIGHTS['excused']);
        $this->assertSame(
            DailyAttendance::WEIGHTS['leave'],
            DailyAttendance::WEIGHTS['excused'],
        );
    }

    public function test_two_lectures_in_one_day_stops_the_migration(): void
    {
        $this->classRow('2026-08-03', 'present');
        $this->classRow('2026-08-03', 'late');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/more than one row for the same student on the same day/');

        ClassAttendanceBackfill::run();
    }

    public function test_nothing_is_written_when_the_migration_refuses(): void
    {
        $this->classRow('2026-08-03', 'present');
        $this->classRow('2026-08-03', 'late');

        try {
            ClassAttendanceBackfill::run();
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, DailyAttendance::count(), 'The check runs before the first insert.');
    }

    public function test_the_backfill_can_be_undone(): void
    {
        $this->classRow('2026-08-03', 'present');
        $this->classRow('2026-08-04', 'excused');
        $this->registerRow('2026-08-10', 'present');

        ClassAttendanceBackfill::run();
        DB::table('attendance_records')->delete();

        $moved = ClassAttendanceBackfill::reverse();

        $this->assertSame(2, $moved);
        $this->assertSame(2, DB::table('attendance_records')->count());
        $this->assertSame(1, DailyAttendance::count(), 'Only the register\'s own row is left.');
        $this->assertSame('2026-08-10', DailyAttendance::sole()->date->toDateString());
    }

    /**
     * The date-cast trap, for the seventh-and-eighth time. A stored date comes
     * back "Y-m-d H:i:s" on a store that keeps the time part and "Y-m-d" on
     * one that truncates; the backfill compares both tables' dates as calendar
     * days in PHP so a conflict is seen on either.
     */
    public function test_a_conflict_is_seen_whatever_shape_the_stored_date_is_in(): void
    {
        $this->registerRow('2026-08-03', 'absent');
        // Written with a time part, the way a date-cast attribute serialises.
        $this->classRow('2026-08-03 00:00:00', 'present');

        $result = ClassAttendanceBackfill::run();

        $this->assertSame(1, $result['conflicts']);
        $this->assertSame(0, $result['inserted']);
    }
}
