<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Console\Console;
use Phalanx\Console\Command\CommandGroup;
use Phalanx\Console\Style\Bundle;
use Phalanx\Demos\Console\InteractiveInput\RegisterCommand;
use Phalanx\Demos\Console\InteractiveInput\SetConfigCommand;
use Phalanx\Demos\Console\InteractiveInput\ShowConfigCommand;

return static fn(array $context): \Closure => static function () use ($context): int {
    return Console::starting($context)
        ->providers(new Bundle())
        ->commands(CommandGroup::of([
            'register' => RegisterCommand::class,
            'config'   => CommandGroup::of([
                'show' => ShowConfigCommand::class,
                'set'  => SetConfigCommand::class,
            ], description: 'Demo configuration commands.'),
        ]))
        ->run();
};
