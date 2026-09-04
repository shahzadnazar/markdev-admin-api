<?php

namespace App\Console\Commands;

use App\Models\BiometricPunch;
use App\Models\DailyAttendance;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Finds days a student was marked absent although they scanned in.
 *
 * Until the day close learned to read punches, a student in manual mode who
 * scanned their finger and whose instructor then forgot to mark the register
 * was closed as absent — and an absence is billable. This reports those days
 * so they can be judged; it writes nothing, because an absence an admin
 * entered on purpose is theirs to keep, and only they can tell the two apart.
 */
class AuditPunchedAbsences extends Command
{
    protected $signature = 'attendance:audit-punched-absences
        {--since= : Earliest day to inspect, defaults to 120 days back}
        {--until= : Latest day to inspect, defaults to today}';

    protected $description = 'Report absences contradicted by a biometric punch on the same day';

    public function handle(): int
    {
        $since = ($this->option('since') ? Carbon::parse($this->option('since')) : now()->subDays(120))->startOfDay();
        $until = ($this->option('until') ? Carbon::parse($this->option('until')) : now())->startOfDay();

        // Half-open on both sides: `date` is a date-cast column and comparing
        // it with equality — or with a <= upper bound — silently misses rows.
        $absences = DailyAttendance::query()
            ->where('status', 'absent')
            ->where('date', '>=', $since->toDateString())
            ->where('date', '<', $until->copy()->addDay()->toDateString())
            ->with('user:id,name,email')
            ->orderBy('date')
            ->get();

        if ($absences->isEmpty()) {
            $this->info('No absences in that window.');

            return self::SUCCESS;
        }

        $punches = BiometricPunch::query()
            ->whereIn('user_id', $absences->pluck('user_id')->unique()->values())
            ->where('punched_at', '>=', $since)
            ->where('punched_at', '<', $until->copy()->addDay())
            ->orderBy('punched_at')
            ->get(['user_id', 'punched_at'])
            // Keyed on the day as text, for the same reason.
            ->groupBy(fn (BiometricPunch $punch) => $punch->user_id.'|'.$punch->punched_at->toDateString());

        $rows = $absences
            ->map(function (DailyAttendance $record) use ($punches) {
                $day = Carbon::parse($record->date)->toDateString();
                $punch = $punches->get($record->user_id.'|'.$day)?->first();

                return $punch === null ? null : [
                    $day,
                    $record->user?->name ?? '#'.$record->user_id,
                    $record->user?->email ?? '',
                    $punch->punched_at->format('g:i A'),
                    $record->source ?? '',
                ];
            })
            ->filter()
            ->values();

        if ($rows->isEmpty()) {
            $this->info(sprintf('%d absence(s) inspected — none contradicted by a punch.', $absences->count()));

            return self::SUCCESS;
        }

        $this->table(['Date', 'Student', 'Email', 'First punch', 'Marked by'], $rows);
        $this->warn(sprintf(
            '%d of %d absence(s) have a punch on the same day. Nothing was changed — correct the ones that are wrong from the daily register.',
            $rows->count(),
            $absences->count(),
        ));

        return self::SUCCESS;
    }
}
