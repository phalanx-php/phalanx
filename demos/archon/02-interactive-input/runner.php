<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Console\Style\ConsoleServiceBundle;
use Phalanx\Demos\Archon\InteractiveInput\RegisterCommand;
use Phalanx\Demos\Archon\InteractiveInput\SetConfigCommand;
use Phalanx\Demos\Archon\InteractiveInput\ShowConfigCommand;

return static fn(array $context): \Closure => static function () use ($context): int {
    return Archon::starting($context)
        ->providers(new ConsoleServiceBundle())
        ->commands(CommandGroup::of([
            'register' => RegisterCommand::class,
            'config'   => CommandGroup::of([
                'show' => ShowConfigCommand::class,
                'set'  => SetConfigCommand::class,
            ], description: 'Demo configuration commands.'),
        ]))
        ->run();
};
