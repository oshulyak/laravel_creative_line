<?php

namespace App\Models\Traits;

use App\LogFormatters\ModelLogFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * @mixin Model
 *
 * @method static void created(callable $callback)
 * @method static void updated(callable $callback)
 * @method static void deleted(callable $callback)
 * @method static void retrieved(callable $callback)
 */
trait HasLog {
    protected static function bootHasLog(): void {
        static::created(function (Model $model): void {
            self::logModelEvent($model, 'created', [
                'id' => $model->getKey(),
                'attributes' => $model->getAttributes(),
            ]);
        });

        static::updated(function (Model $model): void {
            self::logModelEvent($model, 'updated', [
                'id' => $model->getKey(),
                'changed_attributes' => $model->getChanges(),
            ]);
        });

        static::deleted(function (Model $model): void {
            self::logModelEvent($model, 'deleted', [
                'id' => $model->getKey(),
            ]);
        });

        static::retrieved(function (Model $model): void {
            self::logModelEvent($model, 'retrieved', [
                'id' => $model->getKey(),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function logModelEvent(Model $model, string $event, array $context): void {
        $modelName = Str::of(class_basename($model))
            ->snake()
            ->lower()
            ->toString();

        $logger = Log::build([
            'driver' => 'single',
            'path' => storage_path("logs/{$modelName}/{$event}.log"),
            'level' => 'info',
            'replace_placeholders' => true,
        ]);

        (new ModelLogFormatter)($logger);

        $logger->info('{model} {event}', [
            'model' => $model::class,
            'event' => $event,
            ...$context,
        ]);
    }
}
