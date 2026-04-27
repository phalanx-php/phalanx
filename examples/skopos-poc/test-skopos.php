#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Phalanx\Skopos\DevServer;
use Phalanx\Skopos\Process;

DevServer::create()
    ->process(
        Process::named('ticker')
            ->command('php -r "while(true){echo date(\"H:i:s\").\" tick\n\";sleep(2);}"')
            ->ready('/tick/')
    )
    ->process(
        Process::named('counter')
            ->command('php -r "\$i=0;while(true){echo \"count: \".(++\$i).\"\n\";sleep(3);}"')
    )
    ->run();
