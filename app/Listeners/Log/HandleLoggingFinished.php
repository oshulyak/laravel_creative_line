<?php

namespace App\Listeners\Log;

use App\Events\Log\LoggingFinished;

class HandleLoggingFinished {
    public function handle(LoggingFinished $event): void {
        if (! app()->runningInConsole()) {
            return;
        }

        echo 'HandleLoggingFinished: логирование завершено '
            .class_basename($event->model::class)
            .'#'.$event->model->getKey()
            .' action='.$event->action
            .' log#'.$event->log->id
            .PHP_EOL;
    }
}
