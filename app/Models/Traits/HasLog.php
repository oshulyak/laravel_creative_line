<?php

namespace App\Models\Traits;

use App\Models\Log;
use Illuminate\Database\Eloquent\Model;

trait HasLog {
    protected static function bootHasLog(): void {
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
        Log::writeForModel($model, $action);
    }
}
