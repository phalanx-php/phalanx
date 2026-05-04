<?php

declare(strict_types=1);

namespace Phalanx\Console\Input;

use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

/**
 * Registers ConsoleInput as a process-wide singleton.
 *
 * Note: terminal restore is best-effort on shutdown. Callers that flip
 * raw mode mid-scope are responsible for pairing enableRawMode() with
 * a $scope->onDispose($consoleInput->restore(...)) so cancellation/error
 * paths restore correctly. The singleton's onShutdown is a final guard
 * for processes that exit without disposing the scope cleanly.
 */
final class ConsoleInputServiceBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $services->singleton(ConsoleInput::class)
            ->factory(static fn(): ConsoleInput => new ConsoleInput());
    }
}
