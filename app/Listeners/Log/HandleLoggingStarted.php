<?php

namespace App\Listeners\Log;

use App\Events\Log\LoggingStarted;

class HandleLoggingStarted {
    public function handle(LoggingStarted $event): void {
        if (! app()->runningInConsole()) {
            return;
        }

        echo 'HandleLoggingStarted: начинается логирование '
            .class_basename($event->model::class)
            .'#'.$event->model->getKey()
            .' action='.$event->action
            .PHP_EOL;
    }
}
