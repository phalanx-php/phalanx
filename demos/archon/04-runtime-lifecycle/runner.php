<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Console\Style\ConsoleServiceBundle;
use Phalanx\Demos\Archon\RuntimeLifecycle\WatchCommand;

return static fn(array $context): \Closure => static function () use ($context): int {
    return Archon::starting($context)
        ->providers(new ConsoleServiceBundle())
        ->commands(CommandGroup::of([
            'watch' => WatchCommand::class,
        ]))
        ->run();
};
