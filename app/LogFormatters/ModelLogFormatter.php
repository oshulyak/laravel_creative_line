<?php

namespace App\LogFormatters;

use Illuminate\Log\Logger;
use Monolog\Formatter\LineFormatter;

class ModelLogFormatter {
    public function __invoke(Logger $logger): void {
        foreach ($logger->getHandlers() as $handler) {
            $handler->setFormatter(new LineFormatter(
                '[%datetime%] %channel%.%level_name%: %message% %context%'.PHP_EOL
            ));
        }
    }
}
