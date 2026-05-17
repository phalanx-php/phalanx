<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Skopos\Process;
use Phalanx\Skopos\Skopos;

return static fn(array $context): \Closure => static function () use ($context): int {
    return Skopos::starting($context)
        ->process(
            Process::named('php-server')
                ->command('php -S 127.0.0.1:8088 -t ' . __DIR__ . '/public')
                ->ready('/Development Server.*started/i')
                ->asServer()
                ->watch([__DIR__ . '/public'], ['php']),
        )
        ->run();
};
