<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the `excused` attendance status; its rows become `present`.
 *
 * `excused` was never a decision this project made. It arrived with the
 * class-attendance sheet (bfc3d89) because that table had the word and the
 * register had no equivalent, and it outlived the table it came from —
 * attendance_records was dropped in 2026_09_10_090200, so nothing can create
 * an excused row any more. What is left is a fifth status nobody chose.
 *
 * WHY `present` AND NOT THE ALTERNATIVES.
 *
 * `leave` would be a lie. It asserts an approved leave_application_day exists,
 * and LeaveAllowance spends the student's monthly quota against those
 * applications. These rows have no application behind them, so relabelling
 * them would put a claim in the register that nothing supports AND charge
 * students for days they never applied for.
 *
 * `absent` would punish them. It locks behind the admin-only correction rule
 * (946e8e2) and is billable since 459f3cc, so a day somebody had already
 * decided to excuse could become a fine.
 *
 * "Excused" meant: do not penalise this student for this day. `present`
 * preserves that intent, and is the only one of the four that does.
 *
 * THE COST, stated rather than buried: an excused day scored 50 and a present
 * day scores 100, so an affected student's attendance percentage RISES. That
 * is the honest consequence of retiring a half-credit status — the alternative
 * is keeping a status nobody wants in order to avoid moving three rows.
 *
 * The stored attendance_weight_excused setting goes with it; with the status
 * gone the row is a number nothing reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('daily_attendance_records')) {
            $rows = DB::table('daily_attendance_records')->where('status', 'excused')->count();

            DB::table('daily_attendance_records')
                ->where('status', 'excused')
                ->update(['status' => 'present']);

            $this->say("  excused attendance rows converted to present: {$rows}");
        }

        if (Schema::hasTable('settings')) {
            $removed = DB::table('settings')->where('key', 'attendance_weight_excused')->delete();

            $this->say("  attendance_weight_excused setting rows removed: {$removed}");
        }
    }

    /**
     * Not reversible, deliberately.
     *
     * Once an excused row reads `present` it is indistinguishable from a day
     * the student actually attended — nothing records which rows were which,
     * and inventing a rule to pick some back out would be worse than leaving
     * them. Rolling back therefore leaves the data converted and only removes
     * the migration's own record of having run, which is clean rather than
     * silent: this comment is where somebody finds out why.
     *
     * The setting is not restored either; the code that read it is gone.
     */
    public function down(): void
    {
        // Intentionally empty. See the note above.
    }

    protected function say(string $line): void
    {
        if (isset($this->output)) {
            $this->output->writeln($line);
        }
    }
};
