<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The two dials on the delivery score, and nothing else.
 *
 * Settings-backed with constants as the fallback, read through Setting::cached
 * — the same shape as AttendanceWeights and ProgressWeights, which load the
 * whole table once per request and rescue a missing table rather than taking
 * the panel down. Deliberately NOT Cache::remember; that pattern cost this
 * project a 30-second timeout once already.
 *
 * TWO DIALS, ON PURPOSE. A third would be a third way for two academies to
 * disagree about what a score means, and the formula is already the thing
 * people will argue about.
 */
class DeliveryScore
{
    /**
     * Finished stints someone needs before a percentage is shown at all.
     *
     * One finished task must not decide whether somebody reads 0% or 100%.
     * Below this the screens say "not enough completed work yet", which is a
     * different statement from a low score and has to stay different.
     */
    public const DEFAULT_MINIMUM_STINTS = 3;

    /**
     * `same` — early is on time. `better` — early offsets lateness elsewhere.
     *
     * THE `better` LABEL SAYS WHAT THE DIAL DOES, not what its name suggests.
     * It used to read "Early counts for more than on time", and that is false in
     * the case anybody checks first: the score is capped at 100, so somebody
     * early on everything reads 100 and somebody on time on everything also
     * reads 100. The dial cannot separate those two people at all.
     *
     * What it CAN do is partly offset a late stint with an early one, which is
     * the only record it changes — and the cap is right, because a score above
     * full marks is nonsense. So the wording moved rather than the maths. The
     * figure that DOES separate two people both sitting at 100 is the count of
     * early stints, which the score component now draws beside the others.
     */
    public const EARLY_MODES = [
        'same' => 'Early counts the same as on time',
        'better' => 'Early offsets late stints (the score still caps at 100%)',
    ];

    public const DEFAULT_EARLY_MODE = 'same';

    /**
     * What an early day is worth under `better`.
     *
     * A constant, not a third setting: the dial is whether early is rewarded,
     * and by how much is a decision this codebase makes once so two academies
     * comparing notes are comparing the same number. The percentage is capped
     * at 100 afterwards, so the bonus lifts somebody whose other stints were
     * late rather than inventing a score above full marks — which is also why
     * the `better` label promises exactly that and no more.
     */
    public const EARLY_MULTIPLIER = 1.25;

    public const MINIMUM_KEY = 'delivery_minimum_stints';

    public const EARLY_MODE_KEY = 'delivery_early_mode';

    /** Finished stints required before a percentage appears. */
    public static function minimumStints(): int
    {
        $stored = Setting::cached(self::MINIMUM_KEY);

        if ($stored === null || ! is_numeric($stored)) {
            return self::DEFAULT_MINIMUM_STINTS;
        }

        $value = (int) $stored;

        // A hand-edited row outside the range the form allows falls back
        // rather than switching the guard off altogether.
        return $value >= 1 && $value <= 50 ? $value : self::DEFAULT_MINIMUM_STINTS;
    }

    public static function earlyMode(): string
    {
        $stored = (string) (Setting::cached(self::EARLY_MODE_KEY) ?? '');

        return array_key_exists($stored, self::EARLY_MODES) ? $stored : self::DEFAULT_EARLY_MODE;
    }

    public static function earlyCountsForMore(): bool
    {
        return self::earlyMode() === 'better';
    }

    /** What one allowed day of an `early` stint is worth in the numerator. */
    public static function earlyWeight(): float
    {
        return self::earlyCountsForMore() ? self::EARLY_MULTIPLIER : 1.0;
    }
}
