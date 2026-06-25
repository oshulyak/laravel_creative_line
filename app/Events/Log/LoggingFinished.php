<?php

namespace App\Events\Log;

use App\Models\Log;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

class LoggingFinished {
    use Dispatchable;

    public function __construct(
        public Model $model,
        public string $action,
        public Log $log,
    ) {}
}
