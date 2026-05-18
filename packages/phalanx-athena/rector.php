<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withPhpSets(php84: true)
    ->withSkip([
        __DIR__ . '/vendor',
        \Rector\Php81\Rector\Property\ReadOnlyPropertyRector::class,
        \Rector\Php82\Rector\Class_\ReadOnlyClassRector::class,
    ]);
