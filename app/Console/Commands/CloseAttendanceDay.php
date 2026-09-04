<?php

namespace App\Console\Commands;

use App\Models\DailyAttendance;
use App\Models\LeaveApplicationDay;
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
 * It only ever fills a blank, in this order:
 *
 *   1. already marked present / late / absent / leave  -> left alone
 *   2. an approved leave day for this student and date -> `leave`
 *   3. otherwise                                        -> `absent`
 *
 * Step 2 is why approving a leave writes nothing at the time: a future
 * approval marks nothing until that day actually closes, and a student who
 * turns up anyway is already present by step 1 before leave is considered.
 * A declined day has no special handling — it falls through to step 3.
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
        $unsettled = $pendingIds->merge($missingIds);
        $count = $unsettled->count();

        // Whose leave was approved for this day. Read here rather than written
        // at approval time, so the day gets its status once, on the day.
        $onLeave = $this->approvedLeaveOn($date, $unsettled);

        $this->line(sprintf(
            '%s: %d expected, %d already marked, %d to settle (%d on approved leave, %d absent)',
            $date,
            $expected->count(),
            $existing->count() - $pendingIds->count(),
            $count,
            $onLeave->count(),
            $count - $onLeave->count(),
        ));

        if ($dryRun || $count === 0) {
            return $count;
        }

        DB::transaction(function () use ($date, $pendingIds, $missingIds, $onLeave) {
            foreach ([true, false] as $isLeave) {
                $status = $isLeave ? 'leave' : 'absent';
                $remarks = $isLeave ? 'Approved leave' : 'Not marked before end of day.';

                $pending = $pendingIds->filter(fn ($id) => $onLeave->contains($id) === $isLeave)->values();
                $missing = $missingIds->filter(fn ($id) => $onLeave->contains($id) === $isLeave)->values();

                if ($pending->isNotEmpty()) {
                    DailyAttendance::onDate($date)
                        ->whereIn('user_id', $pending)
                        ->update([
                            'status' => $status,
                            'source' => 'auto',
                            'marked_at' => now(),
                            'remarks' => $remarks,
                            'updated_at' => now(),
                        ]);
                }

                foreach ($missing->chunk(500) as $chunk) {
                    $rows = $chunk->map(fn ($id) => [
                        'user_id' => $id,
                        'date' => $date,
                        'status' => $status,
                        'source' => 'auto',
                        'remarks' => $remarks,
                        'marked_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all();

                    DailyAttendance::insert($rows);
                }
            }
        });

        return $count;
    }

    /**
     * The students among these with an approved leave day on this date.
     *
     * @param  Collection<int, int>  $userIds
     * @return Collection<int, int>
     */
    protected function approvedLeaveOn(string $date, Collection $userIds): Collection
    {
        if ($userIds->isEmpty()) {
            return collect();
        }

        return LeaveApplicationDay::query()
            ->where('status', LeaveApplicationDay::APPROVED)
            // Half-open, because `date` is a date-cast column: on SQLite it
            // comes back as a full datetime and an equality would match none.
            ->where('date', '>=', $date)
            ->where('date', '<', Carbon::parse($date)->addDay()->toDateString())
            ->whereHas('leaveApplication', fn ($query) => $query->whereIn('user_id', $userIds))
            ->with('leaveApplication:id,user_id')
            ->get()
            ->pluck('leaveApplication.user_id')
            ->unique()
            ->values();
    }
}
