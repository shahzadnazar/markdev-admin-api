<?php

namespace App\Support;

use App\Models\DailyAttendance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moves every `attendance_records` row into the daily register.
 *
 * The academy kept attendance twice — a per-class sheet and a day register —
 * recording overlapping facts about the same student on the same day. That
 * split made two admin screens disagree, took two commits to close the same
 * security hole in (e58ec3c, then 946e8e2), and left the absent lock written
 * twice. The student portal had been merging the two for display all along,
 * so the split only ever existed on the admin side. This is the merge, made
 * permanent.
 *
 * Lives here rather than inline in the migration so it can be run against
 * real data in a test and its counts asserted, instead of being trusted.
 *
 * ## The rules
 *
 * The register wins. It drives the fines and the attendance percentage, and
 * it alone carries the correction trail, so where both tables hold a row for
 * one student on one day the register's row stands and the class row is
 * recorded as a conflict rather than overwriting anything.
 *
 * `excused` stays `excused`. The register had no equivalent and `leave` was
 * not available to borrow: `leave` asserts an approved application exists,
 * LeaveAllowance spends the student's monthly quota on those applications,
 * and writing it for a day with no application behind it would put a claim in
 * the register that nothing supports. So the register learned the word
 * instead, weighted 50 — exactly what the merged student view already scored
 * an excused session at when it relabelled it `leave` on the way to the
 * portal. Every percentage and every fine total therefore comes out
 * unchanged; what changes is that the row now says what actually happened.
 *
 * ## The one-lecture-per-day assumption
 *
 * `daily_attendance_records` is unique on (user_id, date) and stays that way
 * — it is what stops the duplicate-row bugs this codebase has already had.
 * `attendance_records` had no such constraint, so two rows for one student on
 * one day are possible there. That would mean a student attended two lectures
 * in a day, which the academy says cannot happen. Rather than pick one and
 * discard the other, this refuses to run and names the rows.
 */
class ClassAttendanceBackfill
{
    /** Marks the rows this created, so the migration can undo itself. */
    public const SOURCE = 'class';

    /**
     * @return array{
     *     class_rows: int, register_before: int, register_after: int,
     *     inserted: int, conflicts: int, conflict_dates: array<int, string>,
     * }
     */
    public static function run(): array
    {
        $classRows = static::classRows();
        $registerBefore = DB::table('daily_attendance_records')->count();

        static::assertOneLecturePerDay($classRows);

        // Every (user, day) the register already answers for. Read as raw
        // values and normalised in PHP: `date` is a date-cast column, so it
        // comes back "Y-m-d H:i:s" on a store that keeps the time part and
        // "Y-m-d" on one that truncates, and comparing the two forms directly
        // is the trap this codebase has now hit seven times.
        $taken = DB::table('daily_attendance_records')
            ->select('user_id', 'date')
            ->get()
            ->map(fn ($row) => $row->user_id.'@'.static::day($row->date))
            ->flip();

        $insert = [];
        $conflicts = [];

        foreach ($classRows as $row) {
            $day = static::day($row->date);
            $key = $row->user_id.'@'.$day;

            if ($taken->has($key)) {
                $conflicts[] = $day;

                continue;
            }

            $insert[] = [
                'user_id' => $row->user_id,
                'course_id' => $row->course_id,
                'session_title' => $row->session_title,
                'date' => $day,
                'status' => $row->status,
                'remarks' => $row->notes,
                'source' => static::SOURCE,
                'marked_by' => $row->recorded_by,
                // The class sheet never recorded when it was marked, so the
                // row's own creation time is the closest true answer. The
                // column is NOT NULL and inventing now() would date every
                // historic session to the day of the migration.
                'marked_at' => $row->created_at ?: $row->date,
                'last_updated_by' => $row->last_updated_by,
                'last_update_reason' => $row->last_update_reason,
                'last_updated_at' => $row->last_updated_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ];

            // A second class row for the same student and day would now
            // collide with one this same run is about to insert.
            $taken->put($key, true);
        }

        foreach (array_chunk($insert, 500) as $chunk) {
            DailyAttendance::insert($chunk);
        }

        return [
            'class_rows' => $classRows->count(),
            'register_before' => $registerBefore,
            'register_after' => DB::table('daily_attendance_records')->count(),
            'inserted' => count($insert),
            'conflicts' => count($conflicts),
            'conflict_dates' => array_values(array_unique($conflicts)),
        ];
    }

    /**
     * Puts the backfilled rows back where they came from.
     *
     * The inverse the migration's down() needs. Rows an admin has edited since
     * are copied back as they now stand rather than as they arrived — undoing
     * the merge should not also undo a correction someone made afterwards.
     */
    public static function reverse(): int
    {
        $rows = DB::table('daily_attendance_records')->where('source', static::SOURCE)->get();

        foreach ($rows->chunk(500) as $chunk) {
            DB::table('attendance_records')->insert($chunk->map(fn ($row) => [
                'user_id' => $row->user_id,
                'course_id' => $row->course_id,
                'session_title' => $row->session_title,
                'date' => static::day($row->date),
                'status' => $row->status,
                'notes' => $row->remarks,
                'recorded_by' => $row->marked_by,
                'last_updated_by' => $row->last_updated_by,
                'last_update_reason' => $row->last_update_reason,
                'last_updated_at' => $row->last_updated_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ])->all());
        }

        DB::table('daily_attendance_records')->where('source', static::SOURCE)->delete();

        return $rows->count();
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    protected static function classRows(): \Illuminate\Support\Collection
    {
        return DB::table('attendance_records')->orderBy('id')->get();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     */
    protected static function assertOneLecturePerDay(\Illuminate\Support\Collection $rows): void
    {
        $duplicates = $rows
            ->groupBy(fn ($row) => $row->user_id.'@'.static::day($row->date))
            ->filter(fn ($group) => $group->count() > 1);

        if ($duplicates->isEmpty()) {
            return;
        }

        throw new RuntimeException(
            'attendance_records holds more than one row for the same student on the same day, '
                .'which the register\'s unique(user_id, date) cannot accept and the academy says cannot happen: '
                .$duplicates->keys()->take(10)->implode(', ')
                .($duplicates->count() > 10 ? ' (and '.($duplicates->count() - 10).' more)' : '')
                .'. Resolve these by hand before migrating — picking one automatically would discard attendance.',
        );
    }

    /** The calendar day a stored date value names, whatever shape it is in. */
    protected static function day(mixed $value): string
    {
        return Carbon::parse((string) $value)->toDateString();
    }
}
