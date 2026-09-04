<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Opens a day row for every application still awaiting review.
 *
 * Day rows used to appear only when a reviewer ruled on an application, so a
 * pending request reserved nothing. The monthly allowance counts pending days,
 * and without this an application filed before the change would be invisible
 * to that count — the student would appear to have days they had already asked
 * for.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('leave_applications')
            ->where('status', 'pending')
            ->orderBy('id')
            ->each(function (object $leave) {
                $day = Carbon::parse($leave->from_date)->startOfDay();
                $last = Carbon::parse($leave->to_date)->startOfDay();
                $rows = [];

                while ($day->lessThanOrEqualTo($last)) {
                    $rows[] = [
                        'leave_application_id' => $leave->id,
                        'date' => $day->toDateString(),
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    $day->addDay();
                }

                // Skip any day already carrying a row: reviewed applications
                // are not touched, and re-running must not duplicate.
                $existing = DB::table('leave_application_days')
                    ->where('leave_application_id', $leave->id)
                    ->pluck('date')
                    ->map(fn ($date) => Carbon::parse($date)->toDateString())
                    ->all();

                $rows = array_values(array_filter(
                    $rows,
                    fn (array $row) => ! in_array($row['date'], $existing, true),
                ));

                if ($rows !== []) {
                    DB::table('leave_application_days')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        DB::table('leave_application_days')->where('status', 'pending')->delete();
    }
};
