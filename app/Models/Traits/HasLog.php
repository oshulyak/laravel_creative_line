<?php

namespace App\Models\Traits;

use App\Models\Log;
use Illuminate\Database\Eloquent\Model;

trait HasLog {
    protected static function booted(): void {
        static::created(function (Model $model): void {
            self::logModelEvent($model, 'created');
        });

        static::updated(function (Model $model): void {
            self::logModelEvent($model, 'updated');
        });

        static::deleted(function (Model $model): void {
            self::logModelEvent($model, 'deleted');
        });

        static::retrieved(function (Model $model): void {
            self::logModelEvent($model, 'retrieved');
        });
    }

    private static function logModelEvent(Model $model, string $action): void {
        Log::create([
            'model' => $model::class,
            'action' => $action,
            'old_attributes' => $model->getOriginal(),
            'new_attributes' => $model->getAttributes(),
            'changed_attributes' => $model->getDirty(),
        ]);
    }
}
