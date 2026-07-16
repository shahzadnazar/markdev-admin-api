<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
    ];

    /* ------------------------------ Relations ------------------------------ */

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }
}
