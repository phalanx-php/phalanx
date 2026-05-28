<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload_runtime.php';

use App\HelloCommand;
use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;

return static fn(array $context): \Closure => static function () use ($context): int {
    $commands = CommandGroup::of([
        'hello' => [
            HelloCommand::class,
            new CommandConfig(description: 'Says hello.'),
        ],
    ]);

    return Archon::starting($context)
        ->commands($commands)
        ->build()
        ->run();
};
