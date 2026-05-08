<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Fixtures\Commands;

use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Scope\Scope;
use Phalanx\Task\Scopeable;

/**
 * Test command that echoes its name to the captured StreamOutput so
 * ConsoleLens tests can assert on captured stdout.
 */
final class EchoArgvCommand implements Scopeable
{
    public function __invoke(Scope $scope): int
    {
        $output = $scope->service(StreamOutput::class);
        $output->persist('echoed: hello-from-command');

        return 0;
    }
}
