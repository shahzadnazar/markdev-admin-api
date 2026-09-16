<?php

namespace App\Models;

use App\Models\Concerns\ScopesToDay;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One date the academy is closed, e.g. "Eid ul-Fitr" on 2027-03-20.
 *
 * A holiday applies to everybody — it beats a slot's weekday list, because a
 * closed academy is closed for the Saturday group too. Nobody is expected, so
 * nobody is absent and nobody is fined.
 */
class Holiday extends Model
{
    use ScopesToDay, Auditable, SoftDeletes;

    protected $fillable = ['date', 'name'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    // betweenDates() and onDate() come from ScopesToDay. They used to be
    // written out here as well, which is how a rule with one correct form
    // ends up with two implementations of it that can drift apart.

    /** Asia/Karachi, like every date shown in this system. */
    public function label(): string
    {
        return $this->date->format('D, j M Y');
    }
}
