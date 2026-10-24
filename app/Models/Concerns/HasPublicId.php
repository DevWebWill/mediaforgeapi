<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Identificador público con prefijo de tipo (p. ej. "job_01jb...") independiente
 * del id autoincremental interno, que nunca se expone en la API.
 */
trait HasPublicId
{
    abstract public static function publicIdPrefix(): string;

    public static function bootHasPublicId(): void
    {
        static::creating(function (self $model): void {
            $model->public_id ??= static::publicIdPrefix().'_'.strtolower((string) Str::ulid());
        });
    }

    public function scopeWherePublicId(Builder $query, string $publicId): Builder
    {
        return $query->where($this->qualifyColumn('public_id'), $publicId);
    }
}
