<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Strip the phantom time off every date-cast column.
 *
 * A `date` cast serialises through the connection's datetime format, so every
 * row written before the casts were pinned to `date:Y-m-d` stored
 * "2026-09-11 00:00:00". MySQL's DATE column truncated that on the way in;
 * SQLite kept it verbatim. Same row, two spellings, depending on the driver —
 * which is the root of every one of the ten bugs this class has caused.
 *
 * The casts are pinned now, so NEW rows are clean on both. This makes the old
 * ones match. Idempotent: a value with no time in it is left alone, so running
 * it twice changes nothing.
 *
 * This does NOT make equality safe on its own — a Carbon handed to the query
 * builder still binds with a time, so `where('date', today())` still finds
 * nothing. ScopesToDay stays the one sanctioned form. This removes the half of
 * the problem that lives in storage.
 */
return new class extends Migration
{
    /** table => date-cast columns, matching the models' casts. */
    protected array $columns = [
        'daily_attendance_records' => ['date'],
        'learning_activities' => ['date'],
        'leave_application_days' => ['date'],
        'holidays' => ['date'],
        'leave_applications' => ['from_date', 'to_date'],
        'transactions' => ['payment_date'],
        'invoices' => ['activates_at'],
        'fee_plans' => ['starts_at'],
        'student_profiles' => ['date_of_birth', 'date_of_joining'],
        'absence_fine_charges' => ['month'],
    ];

    public function up(): void
    {
        foreach ($this->columns as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                // Only rows that actually carry a time, so the write is a
                // no-op on a database that is already clean — MySQL's DATE
                // columns always are.
                DB::table($table)
                    ->whereNotNull($column)
                    ->where($column, 'like', '% %')
                    ->update([$column => DB::raw("substr({$column}, 1, 10)")]);
            }
        }
    }

    /**
     * Nothing to undo.
     *
     * The time component carried no information — it was always 00:00:00, an
     * artefact of the cast rather than anything anybody wrote. Putting it back
     * would restore the bug, not the data.
     */
    public function down(): void {}
};
