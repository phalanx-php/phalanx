<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\DevServer\Process;
use Phalanx\DevServer\DevServer;

return static fn(array $context): \Closure => static function () use ($context): int {
    return DevServer::starting($context)
        ->process(
            Process::named('php-server')
                ->command('php -S 127.0.0.1:8088 -t ' . __DIR__ . '/public')
                ->ready('/Development Server.*started/i')
                ->asServer()
                ->watch([__DIR__ . '/public'], ['php']),
        )
        ->run();
};
