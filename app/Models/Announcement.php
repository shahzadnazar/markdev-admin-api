<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Announcement extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'author_id',
        'course_id',
        'title',
        'body',
        'is_pinned',
        'published_at',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'published_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    /* ------------------------------ Relations ------------------------------ */

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class);
    }

    /* ------------------------------- Scopes -------------------------------- */

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    /** Published within the announcement's live window. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->published()->where('published_at', '>=', now()->subHours(self::LIVE_HOURS));
    }

    /* ------------------------------- Display ------------------------------- */

    /** How long a fresh announcement keeps its ticker or popup. */
    public const LIVE_HOURS = 24;

    /** Roles whose announcements run as a ticker rather than a popup. */
    protected const STAFF_ROLES = ['super-admin', 'admin', 'manager'];

    /**
     * How this announcement should be surfaced while it is live.
     *
     * Staff announcements run across the top bar so nobody has to open
     * anything; an instructor's are addressed to their own students, so they
     * arrive as a dismissible popup instead.
     */
    public function display(): string
    {
        if (! $this->isLive()) {
            return 'none';
        }

        return $this->author?->hasAnyRole(self::STAFF_ROLES) ? 'ticker' : 'popup';
    }

    public function isLive(): bool
    {
        return $this->published_at !== null
            && $this->published_at->lte(now())
            && $this->published_at->gte(now()->subHours(self::LIVE_HOURS));
    }

    /** When the ticker or popup stops showing. */
    public function liveUntil(): ?\Illuminate\Support\Carbon
    {
        return $this->published_at?->copy()->addHours(self::LIVE_HOURS);
    }
}
