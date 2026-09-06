<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One date the academy is closed, e.g. "Eid ul-Fitr" on 2027-03-20.
 *
 * A holiday applies to everybody — it beats a slot's weekday list, because a
 * closed academy is closed for the Saturday group too. Nobody is expected, so
 * nobody is absent and nobody is fined.
 */
class Holiday extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = ['date', 'name'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    /**
     * Holidays falling in a half-open date range.
     *
     * Half-open on both ends, and never whereDate(): `date` is a date-cast
     * column, so the stored value comes back as a full datetime on SQLite and
     * an equality — or a `<=` upper bound — would miss the last day.
     */
    public function scopeBetweenDates(Builder $query, mixed $from, mixed $to): Builder
    {
        return $query
            ->where('date', '>=', Carbon::parse($from)->startOfDay()->toDateString())
            ->where('date', '<', Carbon::parse($to)->startOfDay()->addDay()->toDateString());
    }

    public function scopeOnDate(Builder $query, mixed $date): Builder
    {
        return $query->betweenDates($date, $date);
    }

    /** Asia/Karachi, like every date shown in this system. */
    public function label(): string
    {
        return $this->date->format('D, j M Y');
    }
}
