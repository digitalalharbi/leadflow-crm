<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Public identifier. Internal ids stay sequential bigints (fast joins/indexes),
 * while every API surface addresses records by uuid.
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function scopeWhereUuid(Builder $query, string $uuid): Builder
    {
        return $query->where($this->getTable().'.uuid', $uuid);
    }
}
