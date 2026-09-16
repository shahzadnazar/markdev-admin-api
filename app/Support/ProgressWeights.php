<?php

namespace App\Support;

use App\Models\Setting;

/**
 * What each component of course progress is worth, out of 100.
 *
 * Progress used to be one number — completed lessons over total lessons — and
 * an academy that also marks attendance, sets quizzes and grades assignments
 * had no way to say that those count for anything. Each component now carries
 * a percentage the admin types, and a checkbox saying whether it counts at all.
 *
 * The admin types every number. Nothing here redistributes: unchecking premium
 * content does not silently move its 20 anywhere, because a rule that moves
 * marks around on its own is a rule nobody can predict from the form. The
 * validator refuses a set of checked percentages that does not total 100 and
 * says what they currently total.
 *
 * An UNCHECKED component keeps its percentage. The checkbox and the number are
 * stored separately for exactly that reason — turning quizzes off for a term
 * and on again must not lose the 20 somebody chose.
 *
 * Settings-backed with constants as the fallback, the same shape as
 * AttendanceWeights: read through Setting::cached, which loads the whole table
 * once per request and rescues a missing table rather than taking the panel
 * down. Deliberately NOT Cache::remember — that pattern cost this project a
 * 30-second timeout once already.
 */
class ProgressWeights
{
    /**
     * The four components and what each is worth when nothing is stored.
     *
     * The keys are also the definition of which components exist: every loop
     * in the system walks this array, so adding a fifth is a change here and
     * a scorer, not a hunt through the codebase.
     */
    public const DEFAULTS = [
        'attendance' => 40,
        'quiz' => 20,
        'assignment' => 20,
        'premium' => 20,
    ];

    /**
     * The components a certificate is judged on — coursework only.
     *
     * Attendance is excluded deliberately and this is the single place that
     * says so. It is the one component a student cannot go back and fix: two
     * missed days in week one would cap them below 100 for good and put the
     * certificate permanently out of reach however well they work afterwards.
     * Absence already has its own consequence in the absence fine. Progress
     * DISPLAYS all four; the certificate checks these three, renormalised
     * among themselves.
     */
    public const COURSEWORK = ['quiz', 'assignment', 'premium'];

    /** Human labels, for the settings form and the API breakdown. */
    public const LABELS = [
        'attendance' => 'Attendance',
        'quiz' => 'Quizzes',
        'assignment' => 'Assignments',
        'premium' => 'Premium content',
    ];

    public static function keyFor(string $component): string
    {
        return 'progress_weight_'.$component;
    }

    public static function enabledKeyFor(string $component): string
    {
        return 'progress_enabled_'.$component;
    }

    /**
     * One component's percentage, 0–100 — whether or not it is checked.
     *
     * A stored value outside 0–100 is not a percentage; the form refuses one
     * and a hand-edited row falls back rather than skewing every figure in the
     * system.
     */
    public static function percentFor(string $component): int
    {
        $default = self::DEFAULTS[$component] ?? 0;
        $stored = Setting::cached(self::keyFor($component));

        if ($stored === null || ! is_numeric($stored)) {
            return $default;
        }

        $value = (int) $stored;

        return $value >= 0 && $value <= 100 ? $value : $default;
    }

    /** Whether a component counts. Unset means on — the defaults total 100. */
    public static function isEnabled(string $component): bool
    {
        $stored = Setting::cached(self::enabledKeyFor($component));

        if ($stored === null) {
            return array_key_exists($component, self::DEFAULTS);
        }

        return filter_var($stored, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /**
     * Every component, checked or not, for the settings form.
     *
     * @return array<string, array{enabled: bool, percent: int}>
     */
    public static function all(): array
    {
        $out = [];

        foreach (array_keys(self::DEFAULTS) as $component) {
            $out[$component] = [
                'enabled' => self::isEnabled($component),
                'percent' => self::percentFor($component),
            ];
        }

        return $out;
    }

    /**
     * Only the components that count, as component => percentage.
     *
     * This is what every calculation starts from. An unchecked component is
     * absent rather than present at zero, so nothing downstream has to
     * remember to skip it — and the portal can leave it off the breakdown
     * entirely rather than showing a row worth nothing.
     *
     * @return array<string, int>
     */
    public static function enabled(): array
    {
        $out = [];

        foreach (array_keys(self::DEFAULTS) as $component) {
            if (self::isEnabled($component)) {
                $out[$component] = self::percentFor($component);
            }
        }

        return $out;
    }

    /** The checked components that count toward a certificate. */
    public static function enabledCoursework(): array
    {
        return array_intersect_key(self::enabled(), array_flip(self::COURSEWORK));
    }

    /** What the checked percentages currently add up to. */
    public static function checkedTotal(): int
    {
        return array_sum(self::enabled());
    }
}
