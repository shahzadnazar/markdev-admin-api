<?php

use App\Support\ClassAttendanceBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * Moves every class-attendance row into the daily register.
 *
 * Runs before the table is dropped and in its own migration, so a database
 * that fails here still has both tables intact and nothing to recover. The
 * rules, and the reasoning behind the `excused` mapping and the register
 * winning every conflict, are in ClassAttendanceBackfill — kept out of this
 * file so they can be run against real data in a test rather than trusted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $result = ClassAttendanceBackfill::run();

        // Printed rather than logged: whoever runs the migration is the person
        // who needs to see how many rows conflicted. Not during tests, where
        // every RefreshDatabase would repeat it into the assertion output.
        $this->say(sprintf(
            '  class attendance backfill: %d class row(s), %d moved, %d already answered by the register, register %d → %d',
            $result['class_rows'],
            $result['inserted'],
            $result['conflicts'],
            $result['register_before'],
            $result['register_after'],
        ));
    }

    public function down(): void
    {
        $this->say(sprintf('  class attendance backfill reversed: %d row(s)', ClassAttendanceBackfill::reverse()));
    }

    protected function say(string $line): void
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo $line."\n";
        }
    }
};
