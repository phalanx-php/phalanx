<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Stage;

use Closure;
use Phalanx\Console\Input\ConsoleInput;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Theatron\Input\EventParser;
use Phalanx\Theatron\Input\InputEvent;

final class InputFiber
{
    private function __construct()
    {
    }

    /** @param Closure(InputEvent): void $dispatch */
    public static function start(
        ExecutionScope $scope,
        ConsoleInput $consoleInput,
        Closure $dispatch,
    ): void {
        $parser = new EventParser();

        $scope->go(static function () use ($scope, $consoleInput, $parser, $dispatch): void {
            while (!$scope->isCancelled) {
                $bytes = $consoleInput->read($scope, 128, 0.1);

                if ($bytes === '') {
                    foreach ($parser->flush() as $event) {
                        $dispatch($event);
                    }
                    continue;
                }

                foreach ($parser->parse($bytes) as $event) {
                    $dispatch($event);
                }
            }
        }, 'theatron-input');
    }
}
