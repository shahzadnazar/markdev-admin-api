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
        'holiday_id',
        'title',
        'body',
        'is_pinned',
        'published_at',
        'live_until',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'published_at' => 'datetime',
            'live_until' => 'datetime',
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

    /**
     * The holiday this notice is about, when it is one.
     *
     * Set to the first day of the range, which is what the announcer matches
     * on to avoid sending a second notice for a holiday it already covered.
     * Holidays are soft-deleted, so removing one does not null this column —
     * the link survives on purpose, so the announcer can find the notice it
     * now has to take down.
     */
    public function holiday(): BelongsTo
    {
        return $this->belongsTo(Holiday::class);
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

    /**
     * Published within the announcement's live window.
     *
     * Most announcements run for a fixed 24 hours from publication. One that
     * carries its own `live_until` runs to that instead: a notice about a
     * three-day holiday has to still be up on the second day and gone once the
     * academy reopens, which no fixed window from the publish time expresses.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->published()->where(fn (Builder $inner) => $inner
            ->where(fn (Builder $fixed) => $fixed
                ->whereNull('live_until')
                ->where('published_at', '>=', now()->subHours(self::LIVE_HOURS)))
            ->orWhere('live_until', '>', now()));
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
        if ($this->published_at === null || $this->published_at->isFuture()) {
            return false;
        }

        return $this->liveUntil()?->isFuture() ?? false;
    }

    /** When the ticker or popup stops showing. */
    public function liveUntil(): ?\Illuminate\Support\Carbon
    {
        if ($this->published_at === null) {
            return null;
        }

        return $this->live_until?->copy() ?? $this->published_at->copy()->addHours(self::LIVE_HOURS);
    }
}
