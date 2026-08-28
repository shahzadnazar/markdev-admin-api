<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use Auditable;

    protected $fillable = [
        'key',
        'value',
        'group',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    /**
     * Every setting, loaded once per request. Null until the first read.
     *
     * @var array<string, mixed>|null
     */
    protected static ?array $memo = null;

    /**
     * Read a setting without querying once per key.
     *
     * This used to go through Cache::remember. On a database cache store that
     * was a bad trade: each key cost a read of the cache table, a read of
     * settings on a miss, and an upsert to write it back — putting writes into
     * the render path of every admin page, where they can queue behind a lock.
     * The whole table is a handful of rows, so one plain SELECT for all of them
     * is fewer queries than the cache path and writes nothing.
     *
     * A missing table must never take the panel down, hence the rescue.
     */
    public static function cached(string $key, mixed $default = null): mixed
    {
        if (static::$memo === null) {
            static::$memo = rescue(
                fn () => static::query()->get(['key', 'value'])->pluck('value', 'key')->all(),
                [],
                false,
            );
        }

        return static::$memo[$key] ?? $default;
    }

    /** Drop the loaded settings so a save takes effect immediately. */
    public static function forgetCached(?string $key = null): void
    {
        // Settings are resolved as one set, so a single key cannot be dropped
        // on its own; clearing all of them costs one query on the next read.
        static::$memo = null;
    }
}
