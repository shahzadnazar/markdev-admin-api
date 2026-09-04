<?php

namespace App\Console\Commands;

use App\Models\BiometricPunch;
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
 *   3. a biometric punch on that date                  -> present or late
 *   4. otherwise                                        -> `absent`
 *
 * Step 2 is why approving a leave writes nothing at the time: a future
 * approval marks nothing until that day actually closes, and a student who
 * turns up anyway is already present by step 1 before leave is considered.
 * A declined day has no special handling — it falls through to the end.
 *
 * Step 3 exists because an absence is billable. In manual mode a punch does
 * not fill the register — the instructor owns it — but the punch is still
 * proof the student was on the premises. Without this, an instructor who
 * forgets to mark the register turns a student who scanned in into a
 * chargeable absence, and the system bills them against its own evidence.
 * It settles the day from the punch rather than deciding anything an
 * instructor still could: they can correct it, and an absence they meant to
 * record is one they can still record before the day closes.
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

        // Who scanned in but has no register row — the ones an absence would
        // wrong. Leave wins over a punch: an approved leave day is settled
        // even if they came in anyway, and the instructor can correct that.
        $punched = $this->punchesOn($day, $unsettled->reject(fn ($id) => $onLeave->contains($id))->values());

        $this->line(sprintf(
            '%s: %d expected, %d already marked, %d to settle (%d on approved leave, %d from a punch, %d absent)',
            $date,
            $expected->count(),
            $existing->count() - $pendingIds->count(),
            $count,
            $onLeave->count(),
            $punched->count(),
            $count - $onLeave->count() - $punched->count(),
        ));

        if ($dryRun || $count === 0) {
            return $count;
        }

        DB::transaction(function () use ($date, $students, $pendingIds, $missingIds, $onLeave, $punched) {
            // What each unsettled student's day becomes, decided once here so
            // the writes below are a plain grouping.
            $outcome = function (int $id) use ($students, $onLeave, $punched): array {
                if ($onLeave->contains($id)) {
                    return ['leave', 'Approved leave', null];
                }

                if ($punch = $punched->get($id)) {
                    $student = $students->firstWhere('id', $id);
                    $status = \App\Support\AttendanceConfig::statusForArrival($punch, $student);

                    return [
                        $status,
                        'Settled from the biometric punch at '.$punch->format('g:i A').'.',
                        $punch->format('H:i:s'),
                    ];
                }

                return ['absent', 'Not marked before end of day.', null];
            };

            // Keyed by the encoded outcome, not the outcome itself: a callback
            // that returns an array tells groupBy to file the item under every
            // element of it, which would mix two students' arrival times.
            $grouped = $pendingIds->groupBy(fn (int $id) => json_encode($outcome($id)));

            $grouped->each(function ($ids) use ($date, $outcome) {
                [$status, $remarks, $arrived] = $outcome($ids->first());

                DailyAttendance::onDate($date)
                    ->whereIn('user_id', $ids)
                    ->update(array_filter([
                        'status' => $status,
                        'source' => 'auto',
                        'marked_at' => now(),
                        'remarks' => $remarks,
                        'arrived_at' => $arrived,
                        'updated_at' => now(),
                    ], fn ($value) => $value !== null));
            });

            foreach ($missingIds->chunk(500) as $chunk) {
                $rows = $chunk->map(function (int $id) use ($date, $outcome) {
                    [$status, $remarks, $arrived] = $outcome($id);

                    return [
                        'user_id' => $id,
                        'date' => $date,
                        'status' => $status,
                        'source' => 'auto',
                        'remarks' => $remarks,
                        'arrived_at' => $arrived,
                        'marked_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                })->all();

                DailyAttendance::insert($rows);
            }
        });

        return $count;
    }

    /**
     * The earliest punch each of these students made on this day.
     *
     * Matched on a half-open range, not an equality: `punched_at` is a
     * datetime, and comparing it to a date would match only midnight.
     *
     * @param  Collection<int, int>  $userIds
     * @return Collection<int, Carbon>  user id => punch time
     */
    protected function punchesOn(Carbon $day, Collection $userIds): Collection
    {
        if ($userIds->isEmpty()) {
            return collect();
        }

        return BiometricPunch::query()
            ->whereIn('user_id', $userIds)
            ->where('punched_at', '>=', $day->copy()->startOfDay())
            ->where('punched_at', '<', $day->copy()->addDay()->startOfDay())
            ->orderBy('punched_at')
            ->get(['user_id', 'punched_at'])
            ->groupBy('user_id')
            ->map(fn ($punches) => $punches->first()->punched_at);
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
