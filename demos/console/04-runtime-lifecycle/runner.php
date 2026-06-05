<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Console\Facade;
use Phalanx\Console\Command\CommandGroup;
use Phalanx\Console\Style\Bundle;
use Phalanx\Demos\Console\RuntimeLifecycle\WatchCommand;

return static fn(array $context): \Closure => static function () use ($context): int {
    return Facade::starting($context)
        ->providers(new Bundle())
        ->commands(CommandGroup::of([
            'watch' => WatchCommand::class,
        ]))
        ->run();
};
