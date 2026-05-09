<?php

declare(strict_types=1);

use Phalanx\Skopos\Process;
use Phalanx\Skopos\Skopos;

require __DIR__ . '/../../../vendor/autoload.php';

return Skopos::starting()
    ->process(
        Process::named('php-server')
            ->command('php -S 127.0.0.1:8088 -t ' . __DIR__ . '/public')
            ->ready('/Development Server.*started/i')
            ->asServer()
            ->watch([__DIR__ . '/public'], ['php']),
    )
    ->liveReload(35729);
