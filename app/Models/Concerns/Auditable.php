<?php

namespace App\Models\Concerns;

use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Attach to any model whose lifecycle must land in the audit trail.
 * Records created/updated/deleted/restored/force-deleted events with
 * old/new attribute snapshots (only the changed keys on update).
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            AuditLogger::log('created', $model->auditModule(), $model->getKey(), null, $model->auditSnapshot());
        });

        static::updated(function (Model $model) {
            $changes = $model->getChanges();
            unset($changes['updated_at']);

            if ($changes === []) {
                return;
            }

            $old = array_intersect_key($model->getOriginal(), $changes);
            AuditLogger::log('updated', $model->auditModule(), $model->getKey(), $old, $changes);
        });

        static::deleted(function (Model $model) {
            $action = method_exists($model, 'isForceDeleting') && $model->isForceDeleting()
                ? 'force_deleted'
                : 'deleted';
            AuditLogger::log($action, $model->auditModule(), $model->getKey(), $model->auditSnapshot(), null);
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model) {
                AuditLogger::log('restored', $model->auditModule(), $model->getKey(), null, $model->auditSnapshot());
            });
        }
    }

    public function auditModule(): string
    {
        return str(class_basename($this))->snake()->plural()->toString();
    }

    /** @return array<string, mixed> */
    public function auditSnapshot(): array
    {
        $attributes = $this->attributesToArray();

        foreach ($this->getHidden() as $hidden) {
            unset($attributes[$hidden]);
        }

        return $attributes;
    }
}
