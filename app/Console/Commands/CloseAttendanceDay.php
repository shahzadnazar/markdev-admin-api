<?php

namespace App\Console\Commands;

use App\Models\BiometricPunch;
use App\Models\DailyAttendance;
use App\Models\LeaveApplicationDay;
use App\Models\TeamAttendance;
use App\Models\TeamLeaveApplicationDay;
use App\Models\User;
use App\Support\AcademyCalendar;
use App\Support\AttendanceConfig;
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
 * Days nobody is expected on are skipped before any of that: a slot that does
 * not run this weekday, an academy-wide non-working day for a student with no
 * slot, or a holiday, which closes the academy for everybody. See
 * AcademyCalendar — the register, the leave allowance and the fine all ask it
 * the same question so they cannot disagree.
 *
 * It only ever fills a blank, in this order:
 *
 *   1. already marked present / late / absent / leave  -> left alone
 *   2. an approved leave day for this student and date -> `leave`
 *   3. a biometric punch on that date                  -> present or late
 *   4. the date is a holiday                           -> `holiday`
 *   5. otherwise                                        -> `absent`
 *
 * Step 2 is why approving a leave writes nothing at the time: a future
 * approval marks nothing until that day actually closes, and a student who
 * turns up anyway is already present by step 1 before leave is considered.
 * A declined day has no special handling — it falls through to the end.
 *
 * Step 4 is last of the three because it is the weakest claim about the day: a
 * holiday says the academy was shut, and both a punch and an approved leave
 * say something about this student that is still true on a day off. Attending
 * on a day off is not an absence, but it did happen, and the register says
 * what happened. Leave granted for a day that turned out to be a holiday
 * spends nothing either way — the allowance is settled when the student
 * applies, and holidays never become leave days there.
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

    protected $description = 'Settle the daily register: absences, holidays and punched days';

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
            // Not a reason to stop any more: the team register is closed by the
            // same command, and an academy with no active students may still
            // have staff to settle.
            $this->info('No active students.');
        }

        $total = 0;
        $teamTotal = 0;

        // Oldest first, so a catch-up run reads in the order the days happened.
        for ($back = $catchUp; $back >= 0; $back--) {
            $day = $date->copy()->subDays($back);

            if ($students->isNotEmpty()) {
                $total += $this->closeDay($day, $students, $dryRun);
            }

            $teamTotal += $this->closeTeamDay($day, $dryRun);
        }

        if ($dryRun) {
            $this->comment('Dry run — nothing written.');
        } else {
            $this->info("{$total} student-day(s) settled.");
            $this->info("{$teamTotal} team-day(s) settled.");
        }

        return self::SUCCESS;
    }

    /**
     * Settle the TEAM register for one day.
     *
     * ONE COMMAND, TWO TABLES — not a second command on a second schedule that
     * somebody forgets to deploy. The team portal keeps its own register, for
     * the reasons the migration gives, but the nightly close is the same
     * decision made twice over two sets of rows.
     *
     * NO SLOTS here: a team member follows the academy's working week and the
     * holiday list, which is the whole rule. Only days where NOTHING is already
     * recorded are touched — a lead who already marked somebody present must
     * not have it overwritten by a job that ran later.
     *
     * Absent is written through the query builder, which fires no model events
     * and therefore walks past LocksAbsences. That is safe here and only here,
     * because these rows are filtered to the ones with no record at all: the
     * close fills blanks and never revisits a settled day. AbsenceLockTest
     * keeps the list of such sites and fails when a new one appears.
     */
    protected function closeTeamDay(Carbon $day, bool $dryRun): int
    {
        $date = $day->toDateString();
        $holiday = AcademyCalendar::holidayName($day);

        // Anybody who holds a team role and is still active. A deactivated
        // account must not accrue absences, and neither must a student.
        $members = User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['team-lead', 'team']))
            ->where('is_active', true)
            ->pluck('id');

        if ($members->isEmpty()) {
            return 0;
        }

        // A weekend the academy is shut produces no row at all — everybody
        // knows, and a label would only clutter the month. A dated holiday DOES
        // get a row, because the date is not obvious and the gap would read as
        // missing data.
        if ($holiday === null && ! AcademyCalendar::isWorkingWeekday($day)) {
            return 0;
        }

        $already = TeamAttendance::query()
            ->whereIn('user_id', $members)
            ->onDate($day)
            ->pluck('user_id');

        $missing = $members->diff($already);

        if ($missing->isEmpty()) {
            return 0;
        }

        if ($holiday !== null) {
            $status = TeamAttendance::HOLIDAY;
        } else {
            // Approved leave beats an absence: the day was agreed in advance.
            $onLeave = $this->approvedTeamLeaveOn($date, $missing);
            $status = null;
        }

        if ($dryRun) {
            return $missing->count();
        }

        $rows = $missing->map(fn (int $id) => [
            'user_id' => $id,
            'date' => $date,
            'status' => $status ?? (($onLeave ?? collect())->contains($id) ? 'leave' : 'absent'),
            'source' => 'system',
            'marked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        DB::table('team_attendance_records')->insert($rows);

        return count($rows);
    }

    /**
     * Team members with an APPROVED leave day on this date.
     *
     * Joined rather than matched with an equality on `date`: it is a date-cast
     * column, and an equality against it matches on one driver and misses on
     * the other.
     *
     * @param  Collection<int, int>  $userIds
     * @return Collection<int, int>
     */
    protected function approvedTeamLeaveOn(string $date, Collection $userIds): Collection
    {
        return TeamLeaveApplicationDay::query()
            ->join('team_leave_applications', 'team_leave_applications.id', '=', 'team_leave_application_days.team_leave_application_id')
            ->where('team_leave_application_days.status', TeamLeaveApplicationDay::APPROVED)
            ->whereIn('team_leave_applications.user_id', $userIds)
            ->onDate($date, 'team_leave_application_days.date')
            ->pluck('team_leave_applications.user_id');
    }

    /**
     * Settle one day, returning how many student-days it settled.
     *
     * @param  Collection<int, User>  $students
     */
    protected function closeDay(Carbon $day, Collection $students, bool $dryRun): int
    {
        $date = $day->toDateString();

        // A holiday closes the academy for everybody, so it is read once for
        // the day rather than per student.
        $holiday = AcademyCalendar::holidayName($day);

        // Who the academy expects today. A slot that does not run this weekday
        // says nothing about it — a Sunday group should not be absent every
        // Monday — and a student with no slot follows `academy_working_days`,
        // which is what stops them collecting an absence every Saturday.
        // A holiday is handled below rather than here: nobody is expected, but
        // the day still gets a row saying which holiday it was.
        $expected = $students->filter(function (User $student) use ($day) {
            $slot = $student->studentProfile?->attendanceSlot;

            return $slot !== null ? $slot->runsOn($day) : AcademyCalendar::isWorkingWeekday($day);
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
        // wrong. Leave still wins over a punch: an approved leave day is
        // settled even if they came in anyway, and the instructor can correct
        // that. A holiday does not win over a punch, which is why this is read
        // on a holiday too.
        $punched = $this->punchesOn($day, $unsettled->reject(fn ($id) => $onLeave->contains($id))->values());

        $settledAsHoliday = $holiday !== null
            ? $count - $punched->count() - $onLeave->count()
            : 0;

        $this->line($holiday !== null
            ? sprintf(
                '%s: %s — %d expected, %d already marked, %d to settle (%d on approved leave, %d from a punch, %d recorded as a holiday, 0 absent)',
                $date,
                $holiday,
                $expected->count(),
                $existing->count() - $pendingIds->count(),
                $count,
                $onLeave->count(),
                $punched->count(),
                $settledAsHoliday,
            )
            : sprintf(
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

        DB::transaction(function () use ($date, $holiday, $students, $pendingIds, $missingIds, $onLeave, $punched) {
            // What each unsettled student's day becomes, decided once here so
            // the writes below are a plain grouping.
            $outcome = function (int $id) use ($holiday, $students, $onLeave, $punched): array {
                if ($onLeave->contains($id)) {
                    return ['leave', 'Approved leave', null];
                }

                if ($punch = $punched->get($id)) {
                    $student = $students->firstWhere('id', $id);
                    $status = AttendanceConfig::statusForArrival($punch, $student);

                    return [
                        $status,
                        'Settled from the biometric punch at '.$punch->format('g:i A').'.',
                        $punch->format('H:i:s'),
                    ];
                }

                if ($holiday !== null) {
                    return [DailyAttendance::HOLIDAY, $holiday, null];
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
     * @return Collection<int, Carbon> user id => punch time
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
