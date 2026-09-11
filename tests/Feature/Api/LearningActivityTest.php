<?php

namespace Tests\Feature\Api;

use App\Models\LearningActivity;
use Illuminate\Support\Facades\DB;

/**
 * Recording learning minutes from the lesson player.
 *
 * The endpoint used to pass DB::raw('minutes + n') as a VALUES entry to
 * updateOrCreate. Valid in the SET clause of the update branch, meaningless in
 * the VALUES list of the insert branch — so the first ping of each day 500'd
 * for every student and its minutes were lost, while every later ping that day
 * worked. These pin both branches and the races between them.
 */
class LearningActivityTest extends ApiTestCase
{
    private function ping(int $courseId, int $lessonId, int $minutes = 5)
    {
        return $this->postJson("/api/v1/courses/{$courseId}/lessons/{$lessonId}/activity", [
            'minutes' => $minutes,
        ]);
    }

    public function test_the_first_ping_of_the_day_creates_the_row_and_does_not_500(): void
    {
        [$course, , $lessons] = $this->makeCourse();
        $student = $this->actingAsStudent();
        $this->enroll($student, $course);

        $this->assertSame(0, LearningActivity::count(), 'the student starts with no activity row');

        $this->ping($course->id, $lessons->first()->id, 7)->assertOk();

        $this->assertSame(1, LearningActivity::count());
        $this->assertSame(7, LearningActivity::firstOrFail()->minutes);
    }

    public function test_a_second_ping_the_same_day_accumulates_into_one_row(): void
    {
        [$course, , $lessons] = $this->makeCourse();
        $student = $this->actingAsStudent();
        $this->enroll($student, $course);

        $this->ping($course->id, $lessons->first()->id, 7)->assertOk();
        $this->ping($course->id, $lessons->first()->id, 5)->assertOk();
        $this->ping($course->id, $lessons->first()->id, 3)->assertOk();

        $this->assertSame(1, LearningActivity::count(), 'one row per user per day');
        $this->assertSame(15, LearningActivity::firstOrFail()->minutes);
    }

    public function test_pings_on_different_days_get_their_own_rows(): void
    {
        [$course, , $lessons] = $this->makeCourse();
        $student = $this->actingAsStudent();
        $this->enroll($student, $course);

        $this->travelTo(now()->setTime(9, 0));
        $this->ping($course->id, $lessons->first()->id, 7)->assertOk();

        $this->travel(1)->days();
        $this->ping($course->id, $lessons->first()->id, 4)->assertOk();

        $this->assertSame(2, LearningActivity::count());
        $this->assertSame([4, 7], LearningActivity::pluck('minutes')->sort()->values()->all());

        $this->travelBack();
    }

    /**
     * The day boundary is Karachi's, not UTC's.
     *
     * 23:30 in Karachi is 18:30 UTC the same day; 00:30 in Karachi is 19:30 UTC
     * the PREVIOUS day. A tally keyed on the UTC date would put those two pings
     * in one row, or in the wrong pair of rows.
     */
    public function test_the_day_rolls_over_at_midnight_in_karachi(): void
    {
        [$course, , $lessons] = $this->makeCourse();
        $student = $this->actingAsStudent();
        $this->enroll($student, $course);

        $this->travelTo(now()->setTime(23, 30));
        $late = now()->toDateString();
        $this->ping($course->id, $lessons->first()->id, 7)->assertOk();

        $this->travel(60)->minutes();
        $early = now()->toDateString();
        $this->ping($course->id, $lessons->first()->id, 4)->assertOk();

        $this->assertNotSame($late, $early, 'an hour after 23:30 is the next day');
        $this->assertSame(2, LearningActivity::count());

        $this->travelBack();
    }

    /**
     * Two first pings arriving together.
     *
     * Simulated rather than threaded: the second request is made to find no row
     * where one now exists, which is exactly the interleaving that matters —
     * both callers select nothing, both try to insert, the unique index lets
     * one through. The loser must add to the winner's row, not lose its minutes
     * and not 500.
     */
    public function test_concurrent_first_pings_are_both_counted(): void
    {
        $student = $this->actingAsStudent();

        // The row the "other request" created after this one had already looked.
        DB::table('learning_activities')->insert([
            'user_id' => $student->id,
            'date' => now()->toDateString(),
            'minutes' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        LearningActivity::recordMinutes($student->id, 5);

        $this->assertSame(1, LearningActivity::count(), 'the unique index settles it — one row');
        $this->assertSame(12, LearningActivity::firstOrFail()->minutes, 'neither ping was lost');
    }

    public function test_a_non_enrolled_student_is_still_refused(): void
    {
        [$course, , $lessons] = $this->makeCourse(2, [], ['is_preview' => false]);
        $this->actingAsStudent();

        $this->ping($course->id, $lessons->first()->id, 7)->assertForbidden();

        $this->assertSame(0, LearningActivity::count(), 'a refused ping records nothing');
    }

    public function test_a_preview_lesson_is_still_open_to_anyone_signed_in(): void
    {
        [$course, , $lessons] = $this->makeCourse();
        $this->actingAsStudent();

        // makeCourse marks lesson 1 a preview.
        $this->ping($course->id, $lessons->first()->id, 7)->assertOk();
        $this->assertSame(7, LearningActivity::firstOrFail()->minutes);
    }

    public function test_minutes_already_recorded_are_added_to_never_replaced(): void
    {
        [$course, , $lessons] = $this->makeCourse();
        $student = $this->actingAsStudent();
        $this->enroll($student, $course);

        LearningActivity::create([
            'user_id' => $student->id,
            'date' => now()->toDateString(),
            'minutes' => 40,
        ]);

        $this->ping($course->id, $lessons->first()->id, 5)->assertOk();

        $this->assertSame(45, LearningActivity::firstOrFail()->minutes);
    }

    /**
     * The stored date carries no time, on either driver.
     *
     * A plain `date` cast serialises through the connection's datetime format,
     * so SQLite stored "2026-09-11 00:00:00" while MySQL's DATE column
     * truncated the same value to "2026-09-11". An equality lookup then matched
     * in production and missed in the tests. Pinned to Y-m-d, both agree.
     */
    public function test_the_date_column_is_stored_without_a_time(): void
    {
        [$course, , $lessons] = $this->makeCourse();
        $student = $this->actingAsStudent();
        $this->enroll($student, $course);

        $this->ping($course->id, $lessons->first()->id, 7)->assertOk();

        $this->assertSame(
            now()->toDateString(),
            DB::table('learning_activities')->value('date'),
            'a DATE column with a 00:00:00 on it is the trap that hid this bug',
        );
    }

    /**
     * A row written before the cast was pinned is still found.
     *
     * Every SQLite row this table already holds carries a 00:00:00 that a plain
     * `date` cast put there. Equality misses those and the code would insert a
     * second row for the same day, hit the unique index, and — with nothing to
     * re-read — lose the minutes all over again. whereDate matches both shapes,
     * which is why the lookup uses it even though the cast now makes today's
     * rows clean.
     */
    public function test_a_legacy_row_with_a_time_on_it_is_found_not_duplicated(): void
    {
        $student = $this->actingAsStudent();

        DB::table('learning_activities')->insert([
            'user_id' => $student->id,
            'date' => now()->toDateString().' 00:00:00',
            'minutes' => 40,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        LearningActivity::recordMinutes($student->id, 5);

        $this->assertSame(1, LearningActivity::count(), 'the old row is the day\'s row');
        $this->assertSame(45, (int) DB::table('learning_activities')->value('minutes'));
    }

    /**
     * The accumulate is one statement.
     *
     * Read-modify-write in PHP would pass every test above and still lose a
     * ping under real concurrency, so the shape of the statement is asserted,
     * not just the total.
     */
    public function test_the_accumulate_is_a_single_atomic_update(): void
    {
        $student = $this->actingAsStudent();
        LearningActivity::recordMinutes($student->id, 5);

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        LearningActivity::recordMinutes($student->id, 5);

        $updates = array_values(array_filter($statements, fn (string $sql) => str_starts_with($sql, 'update')));

        $this->assertCount(1, $updates);
        $this->assertMatchesRegularExpression(
            '/set\s+"?minutes"?\s*=\s*"?minutes"?\s*\+/i',
            $updates[0],
            'the expression has to be in a SET clause — that is the whole bug',
        );
    }

    /**
     * The same statements, compiled by MySQL's grammar.
     *
     * There is no MySQL server in this environment, so production correctness
     * cannot be proved by execution. What CAN be proved is that the fix uses no
     * construct whose meaning differs per driver: the lookup and the increment
     * are compiled through the MySQL grammar here and asserted to be the same
     * plain SQL the SQLite tests above execute. An upsert with an "on duplicate
     * key update" would not have survived this check, which is why it was not
     * chosen.
     */
    public function test_the_same_shapes_compile_under_the_mysql_grammar(): void
    {
        config(['database.connections.grammar_check' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'unused',
            'username' => 'unused',
            'password' => '',
            'prefix' => '',
        ]]);

        $mysql = DB::connection('grammar_check');

        $lookup = $mysql->table('learning_activities')
            ->where('user_id', 1)
            ->whereDate('date', '2026-09-11')
            ->toSql();

        $this->assertSame(
            'select * from `learning_activities` where `user_id` = ? and date(`date`) = ?',
            $lookup,
        );

        $update = $mysql->getQueryGrammar()->compileUpdate(
            $mysql->table('learning_activities')->where('id', 1),
            ['minutes' => $mysql->raw('`minutes` + 5')],
        );

        $this->assertStringContainsString('set `minutes` = `minutes` + 5', $update);
        $this->assertStringNotContainsString('values', $update, 'an expression in a VALUES list is the bug');
    }
}
