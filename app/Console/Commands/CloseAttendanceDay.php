<?php

namespace App\Console\Commands;

use App\Models\DailyAttendance;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Settles the daily register at the end of the day.
 *
 * A day nobody marked is not the same as a day marked absent, so until this
 * runs the day is simply open — held as `pending` where a row exists, and as
 * no row at all where one doesn't. Neither is shown or counted. At close, both
 * become an absence, which is what an unexplained missing day means.
 *
 * It only ever fills a blank. A student already marked present, late or on
 * approved leave is left exactly as they are, and an approval that lands after
 * the close still rewrites that day to leave.
 */
class CloseAttendanceDay extends Command
{
    protected $signature = 'attendance:close-day
        {--date= : Day to close, defaults to today}
        {--catch-up=0 : Also settle this many earlier days, for runs the scheduler missed}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Mark unrecorded students absent for the day';

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : now()->startOfDay();

        $catchUp = max(0, (int) $this->option('catch-up'));
        $dryRun = (bool) $this->option('dry-run');

        // Only active students are expected to attend; a deactivated account
        // shouldn't accrue absences.
        $students = User::role('student')->where('is_active', true)
            ->with('studentProfile.attendanceSlot')
            ->get();

        if ($students->isEmpty()) {
            $this->info('No active students — nothing to close.');

            return self::SUCCESS;
        }

        $total = 0;

        // Oldest first, so a catch-up run reads in the order the days happened.
        for ($back = $catchUp; $back >= 0; $back--) {
            $total += $this->closeDay($date->copy()->subDays($back), $students, $dryRun);
        }

        if ($dryRun) {
            $this->comment('Dry run — nothing written.');
        } else {
            $this->info("{$total} student-day(s) marked absent.");
        }

        return self::SUCCESS;
    }

    /**
     * Settle one day, returning how many students it marked absent.
     *
     * @param  Collection<int, User>  $students
     */
    protected function closeDay(Carbon $day, Collection $students, bool $dryRun): int
    {
        $date = $day->toDateString();

        // A slot that does not run on this weekday says nothing about it, so
        // its students are not expected and must not collect an absence — a
        // Sunday group should not be absent every Monday. A student with no
        // slot follows the academy-wide day, which is how this always worked.
        $expected = $students->filter(function (User $student) use ($day) {
            $slot = $student->studentProfile?->attendanceSlot;

            return $slot === null || $slot->runsOn($day);
        })->pluck('id');

        if ($expected->isEmpty()) {
            $this->line("{$date}: no students are expected — skipped.");

            return 0;
        }

        $existing = DailyAttendance::onDate($date)
            ->whereIn('user_id', $expected)
            ->pluck('status', 'user_id');

        // Anything already decided — present, late, absent or leave — is left
        // alone. Only the two shapes of "nobody said" are settled.
        $pendingIds = $existing->filter(fn ($status) => $status === DailyAttendance::PENDING)->keys();
        $missingIds = $expected->reject(fn ($id) => $existing->has($id))->values();
        $count = $pendingIds->count() + $missingIds->count();

        $this->line(sprintf(
            '%s: %d expected, %d already marked, %d to settle (%d pending, %d with no row)',
            $date,
            $expected->count(),
            $existing->count() - $pendingIds->count(),
            $count,
            $pendingIds->count(),
            $missingIds->count(),
        ));

        if ($dryRun || $count === 0) {
            return $count;
        }

        DB::transaction(function () use ($date, $pendingIds, $missingIds) {
            if ($pendingIds->isNotEmpty()) {
                DailyAttendance::onDate($date)
                    ->whereIn('user_id', $pendingIds)
                    ->update([
                        'status' => 'absent',
                        'source' => 'auto',
                        'marked_at' => now(),
                        'remarks' => 'Not marked before end of day.',
                        'updated_at' => now(),
                    ]);
            }

            foreach ($missingIds->chunk(500) as $chunk) {
                $rows = $chunk->map(fn ($id) => [
                    'user_id' => $id,
                    'date' => $date,
                    'status' => 'absent',
                    'source' => 'auto',
                    'remarks' => 'Not marked before end of day.',
                    'marked_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all();

                DailyAttendance::insert($rows);
            }
        });

        return $count;
    }
}
