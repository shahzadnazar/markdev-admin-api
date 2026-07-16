<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'user_name',
        'user_role',
        'action',
        'module',
        'record_id',
        'old_values',
        'new_values',
        'ip_address',
        'browser',
        'os',
        'device',
        'url',
        'http_method',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** Search across the common columns used by the admin log viewer. */
    public function scopeSearch($query, ?string $term)
    {
        if ($term === null || trim($term) === '') {
            return $query;
        }

        $like = '%'.trim($term).'%';

        return $query->where(function ($inner) use ($like) {
            $inner->where('user_name', 'like', $like)
                ->orWhere('action', 'like', $like)
                ->orWhere('module', 'like', $like)
                ->orWhere('url', 'like', $like)
                ->orWhere('ip_address', 'like', $like);
        });
    }
}
