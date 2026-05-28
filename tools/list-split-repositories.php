#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/module-manifest.php';

$modules = require dirname(__DIR__) . '/modules.php';

foreach ($modules as $meta) {
    if (! phalanx_module_is_published($meta)) {
        continue;
    }

    echo phalanx_repository_name($meta['package']) . PHP_EOL;
}
