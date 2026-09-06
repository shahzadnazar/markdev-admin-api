<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One sentence of the Rules & Regulations page, before its numbers are filled.
 *
 * The body carries {placeholders} that RuleBook resolves server-side. The
 * portal is given the finished sentence and never sees a template.
 */
class RuleTemplate extends Model
{
    use Auditable;

    protected $fillable = ['key', 'section', 'position', 'body', 'default_body'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('section')->orderBy('position')->orderBy('id');
    }

    /** Whether an admin has reworded this from what shipped. */
    public function isEdited(): bool
    {
        return $this->body !== $this->default_body;
    }

    /**
     * The {placeholders} a body refers to.
     *
     * @return array<int, string>
     */
    public static function placeholdersIn(string $body): array
    {
        preg_match_all('/\{([a-z0-9_]+)\}/i', $body, $matches);

        return array_values(array_unique($matches[1]));
    }
}
