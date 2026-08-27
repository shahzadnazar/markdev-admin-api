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

    /** Resolved once per request on top of the cache. */
    protected static array $memo = [];

    /**
     * Read a setting without hitting the database on every page render.
     *
     * The admin layout reads two of these for each request; they change rarely,
     * so they are cached and flushed when settings are saved. A missing table
     * or cache store must never take the panel down, hence the rescue.
     */
    public static function cached(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, static::$memo)) {
            return static::$memo[$key] ?? $default;
        }

        $value = rescue(
            fn () => \Illuminate\Support\Facades\Cache::remember(
                "setting:{$key}",
                now()->addMinutes(10),
                fn () => static::query()->where('key', $key)->value('value'),
            ),
            null,
            false,
        );

        static::$memo[$key] = $value;

        return $value ?? $default;
    }

    /** Drop cached values so a save takes effect immediately. */
    public static function forgetCached(?string $key = null): void
    {
        $keys = $key !== null ? [$key] : static::query()->pluck('key')->all();

        foreach ($keys as $name) {
            rescue(fn () => \Illuminate\Support\Facades\Cache::forget("setting:{$name}"), null, false);
            unset(static::$memo[$name]);
        }
    }
}
