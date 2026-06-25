<?php

namespace App\Models;

use App\Events\Log\LoggingFinished;
use App\Events\Log\LoggingStarted;
use Illuminate\Database\Eloquent\Model;

class Log extends Model {
    /**
     * @var list<string>
     */
    protected $fillable = [
        'model',
        'action',
        'old_attributes',
        'new_attributes',
        'changed_attributes',
    ];

    public static function writeForModel(Model $model, string $action): self {
        $logAttributes = [
            'model' => $model::class,
            'action' => $action,
            'old_attributes' => $model->getOriginal(),
            'new_attributes' => $model->getAttributes(),
            'changed_attributes' => $model->getDirty(),
        ];

        LoggingStarted::dispatch($model, $action, $logAttributes);

        $log = self::create($logAttributes);

        LoggingFinished::dispatch($model, $action, $log);

        return $log;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array {
        return [
            'old_attributes' => 'array',
            'new_attributes' => 'array',
            'changed_attributes' => 'array',
        ];
    }
}
