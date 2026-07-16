<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HelpCategory extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'icon',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /* ------------------------------ Relations ------------------------------ */

    public function articles(): HasMany
    {
        return $this->hasMany(HelpArticle::class);
    }
}
