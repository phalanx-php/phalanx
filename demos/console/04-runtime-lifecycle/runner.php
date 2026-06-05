<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Console\Application\Console;
use Phalanx\Console\Command\CommandGroup;
use Phalanx\Console\Console\Style\ConsoleServiceBundle;
use Phalanx\Demos\Console\RuntimeLifecycle\WatchCommand;

return static fn(array $context): \Closure => static function () use ($context): int {
    return Console::starting($context)
        ->providers(new ConsoleServiceBundle())
        ->commands(CommandGroup::of([
            'watch' => WatchCommand::class,
        ]))
        ->run();
};
