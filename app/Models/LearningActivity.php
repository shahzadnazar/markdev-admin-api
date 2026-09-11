<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;

class LearningActivity extends Model
{
    protected $fillable = [
        'user_id',
        'date',
        'minutes',
    ];

    protected function casts(): array
    {
        return [
            /*
             * `date:Y-m-d`, not `date`.
             *
             * The column is a DATE and the unique index is (user_id, date), but
             * a plain `date` cast serialises through the connection's datetime
             * format, so the value handed to the driver is "2026-09-11
             * 00:00:00". MySQL's DATE column silently truncates that back to
             * "2026-09-11"; SQLite has no DATE type and stores the string as
             * given. The same row therefore looks different on the two drivers,
             * and an equality lookup that matches in production misses in the
             * tests — measured, not theorised.
             *
             * With the format pinned, both drivers store "2026-09-11". JSON
             * output is unchanged: serializeDate already rendered it as an
             * ISO timestamp and still does.
             */
            'date' => 'date:Y-m-d',
            'minutes' => 'integer',
        ];
    }

    /* ------------------------------ Relations ------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* ------------------------------- Writing ------------------------------- */

    /**
     * Add minutes to a user's tally for a day, atomically.
     *
     * The accumulate is an `UPDATE ... SET minutes = minutes + n` — one
     * statement, so two pings arriving together both land. Reading the row,
     * adding in PHP and saving it back would lose one of them.
     *
     * Two statements rather than one upsert, deliberately. An upsert expressing
     * "on duplicate key update minutes = minutes + n" is a single statement and
     * genuinely race-free, but its syntax and its semantics differ per driver,
     * and this environment has no MySQL server to check the production half
     * against — it would be verified by reading generated SQL and by tests that
     * only ever run on SQLite, which is the exact shape of gap that let the bug
     * this replaces live for a fortnight. A SELECT, an INSERT and an
     * `x = x + n` UPDATE mean the same thing on every driver there is.
     *
     * What that leaves is a race on CREATING the day's first row: two first
     * pings can both find nothing and both insert. The unique index on
     * (user_id, date) settles it — one insert wins, the loser re-reads the
     * winner's row and increments that. Nothing is lost either way.
     *
     * The lookup is whereDate, never `where('date', ...)`. The column is
     * date-cast, and rows written before the cast above was pinned still carry
     * a 00:00:00 on SQLite; whereDate matches them, equality does not.
     */
    public static function recordMinutes(int $userId, int $minutes, ?string $date = null): self
    {
        // now() is Asia/Karachi (config/app.php), so a day rolls over at
        // midnight in Karachi rather than in UTC.
        $date ??= now()->toDateString();

        $find = fn () => static::query()
            ->where('user_id', $userId)
            ->whereDate('date', $date)
            ->first();

        $activity = $find();

        if ($activity === null) {
            try {
                $activity = static::create([
                    'user_id' => $userId,
                    'date' => $date,
                    'minutes' => 0,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Someone else created today's row between the select and the
                // insert. Their row is the one to add to.
                $activity = $find();
            }
        }

        abort_if($activity === null, 500, 'Could not record learning activity.');

        $activity->increment('minutes', $minutes);

        return $activity;
    }
}
