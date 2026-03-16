<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/packages'])
    ->withPhpSets(php84: true)
    ->withSkip([
        __DIR__ . '/vendor',
        '*/vendor/*',
        '*/node_modules/*',
        \Rector\Php81\Rector\Property\ReadOnlyPropertyRector::class,
        \Rector\Php82\Rector\Class_\ReadOnlyClassRector::class,
    ]);
