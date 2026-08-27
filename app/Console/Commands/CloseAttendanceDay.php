<?php

namespace App\Console\Commands;

use App\Models\DailyAttendance;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Settles the daily register at the end of the day.
 *
 * A day nobody marked is not the same as a day marked absent, so until this
 * runs the day is simply open — held as `pending` where a row exists, and as
 * no row at all where one doesn't. Neither is shown or counted. At close, both
 * become an absence, which is what an unexplained missing day means.
 */
class CloseAttendanceDay extends Command
{
    protected $signature = 'attendance:close-day
        {--date= : Day to close, defaults to today}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Mark unrecorded students absent for the day';

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->toDateString()
            : now()->toDateString();

        $dryRun = (bool) $this->option('dry-run');

        // Only active students are expected to attend; a deactivated account
        // shouldn't accrue absences.
        $students = User::role('student')->where('is_active', true)->pluck('id');

        if ($students->isEmpty()) {
            $this->info('No active students — nothing to close.');

            return self::SUCCESS;
        }

        $existing = DailyAttendance::whereDate('date', $date)
            ->whereIn('user_id', $students)
            ->pluck('status', 'user_id');

        $pendingIds = $existing->filter(fn ($status) => $status === DailyAttendance::PENDING)->keys();
        $missingIds = $students->reject(fn ($id) => $existing->has($id))->values();

        $this->line("Closing {$date}");
        $this->line("  pending rows to settle : {$pendingIds->count()}");
        $this->line("  students with no row   : {$missingIds->count()}");

        if ($dryRun) {
            $this->comment('Dry run — nothing written.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($date, $pendingIds, $missingIds) {
            if ($pendingIds->isNotEmpty()) {
                DailyAttendance::whereDate('date', $date)
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

        $total = $pendingIds->count() + $missingIds->count();
        $this->info("Closed {$date} — {$total} student(s) marked absent.");

        return self::SUCCESS;
    }
}
