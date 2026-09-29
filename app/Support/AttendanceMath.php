<?php

namespace App\Support;

/**
 * Turning day counts into a percentage — for any register, not just one.
 *
 * EXTRACTED, NOT COPIED. These two were static methods on DailyAttendance, and
 * the team portal needs the identical arithmetic over its own table. A second
 * copy of a weighting rule is a second answer waiting to disagree with the
 * first, which is exactly the kind of drift this codebase has paid for before.
 * DailyAttendance still exposes them and simply delegates, so every academy
 * caller and every academy test is untouched.
 *
 * The weights come from AttendanceWeights, which is settings-backed. The team
 * register shares them deliberately: nobody asked for a second set of weights,
 * and inventing one would be a sixth number for two populations to disagree
 * about. The team's own numbers — office start, grace, allowances, fine rate —
 * are separate, because those were asked for.
 */
class AttendanceMath
{
    /**
     * Weighted attendance for a set of day counts, as a percentage.
     *
     * Null when there are no counted days at all: "nothing marked yet" is a
     * different statement from 0%, and the screens keep them different.
     *
     * @param  array<string, int>  $counts  status => number of days
     */
    public static function weightedPercent(array $counts): ?float
    {
        $days = 0;
        $earned = 0;

        foreach (AttendanceWeights::all() as $status => $weight) {
            $n = (int) ($counts[$status] ?? 0);
            $days += $n;
            $earned += $n * $weight;
        }

        return $days > 0 ? round($earned / $days, 1) : null;
    }

    /** SQL that sums the weights, for computing the percentage in one query. */
    public static function weightedSumSql(string $column = 'status'): string
    {
        $cases = [];

        foreach (AttendanceWeights::all() as $status => $weight) {
            // Interpolated as an int, and the statuses are the register's own
            // constants — nothing here comes from a request.
            $cases[] = "when {$column} = '{$status}' then ".(int) $weight;
        }

        return 'sum(case '.implode(' ', $cases).' else 0 end)';
    }
}
