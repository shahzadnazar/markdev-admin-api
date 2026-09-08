<?php

namespace Tests\Feature\Admin;

use App\Models\BiometricPunch;
use App\Models\DailyAttendance;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationDay;
use Tests\TestCase;

/**
 * Every status a model can write must fit the column that stores it.
 *
 * `leave_applications.status` was 12 characters wide for
 * `pending | approved | rejected`. Adding `partially_approved` — 18 — made a
 * partial approval fail on MySQL with "Data too long for column 'status'",
 * and no test caught it because SQLite does not enforce VARCHAR length: the
 * suite wrote the over-long value happily on every run.
 *
 * So this reads the width from the migrations rather than from the database.
 * A static check is the only kind that fails on both engines, and the two
 * things it compares — the constant and the column — are both in source, so
 * a status added without widening its column is caught before it ships.
 */
class StatusColumnWidthTest extends TestCase
{
    /**
     * table.column => the values the application writes into it.
     *
     * @return array<string, array<int, string>>
     */
    public static function columns(): array
    {
        return [
            'leave_applications.status' => LeaveApplication::STATUSES,
            'leave_application_days.status' => LeaveApplicationDay::STATUSES,
            'daily_attendance_records.status' => [
                ...DailyAttendance::STATUSES,
                DailyAttendance::PENDING,
                DailyAttendance::HOLIDAY,
            ],
            'daily_attendance_records.source' => ['manual', 'biometric', 'auto'],
            'biometric_punches.status' => [
                BiometricPunch::STATUS_PENDING,
                BiometricPunch::STATUS_PROCESSED,
                BiometricPunch::STATUS_UNMATCHED,
                BiometricPunch::STATUS_SKIPPED,
            ],
        ];
    }

    public function test_every_status_fits_the_column_that_stores_it(): void
    {
        foreach (self::columns() as $target => $values) {
            [$table, $column] = explode('.', $target);
            $width = $this->declaredWidth($table, $column);

            $this->assertNotNull($width, "No width declared for {$target} in any migration.");

            foreach ($values as $value) {
                $this->assertLessThanOrEqual(
                    $width,
                    strlen($value),
                    "'{$value}' is ".strlen($value)." characters but {$target} is {$width}. "
                        .'MySQL refuses the write; SQLite does not, so only this check sees it.',
                );
            }
        }
    }

    /**
     * The width a column ends up with, read from the migrations in order.
     *
     * The last declaration wins, so a later `->change()` that widens a column
     * is what counts rather than the original `Schema::create`.
     */
    protected function declaredWidth(string $table, string $column): ?int
    {
        $width = null;

        foreach ($this->migrationFiles() as $file) {
            $source = file_get_contents($file);

            // Only look inside blocks for this table, so a column of the same
            // name on another table cannot answer for it.
            preg_match_all(
                "/Schema::(?:create|table)\(\s*'".preg_quote($table, '/')."'.*?(?=Schema::|\z)/s",
                $source,
                $blocks,
            );

            foreach ($blocks[0] as $block) {
                if (preg_match_all("/string\(\s*'".preg_quote($column, '/')."'\s*,\s*(\d+)\s*\)/", $block, $found)) {
                    $width = (int) end($found[1]);
                }
            }
        }

        return $width;
    }

    /** @return array<int, string> in the order Laravel runs them. */
    protected function migrationFiles(): array
    {
        $files = glob(database_path('migrations/*.php'));
        sort($files);

        return $files;
    }
}
