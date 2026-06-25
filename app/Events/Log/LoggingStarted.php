<?php

namespace App\Events\Log;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

class LoggingStarted {
    use Dispatchable;

    /**
     * @param  array{model: class-string<Model>, action: string, old_attributes: array<string, mixed>, new_attributes: array<string, mixed>, changed_attributes: array<string, mixed>}  $logAttributes
     */
    public function __construct(
        public Model $model,
        public string $action,
        public array $logAttributes,
    ) {}
}
