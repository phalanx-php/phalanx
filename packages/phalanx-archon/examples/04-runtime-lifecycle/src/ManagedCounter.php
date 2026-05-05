<?php

declare(strict_types=1);

namespace Acme\ArchonDemo\Lifecycle;

use Phalanx\Archon\Console\Output\StreamOutput;

/**
 * Mock managed resource. open() prints a banner; close() prints a cleanup
 * banner — paired with $scope->onDispose so the resilience demo can see the
 * cleanup line fire even when the command body is interrupted.
 */
final class ManagedCounter
{
    public readonly int $id;

    private static int $sequence = 0;
    private bool $closed = false;

    public function __construct(private readonly StreamOutput $output)
    {
        $this->id = ++self::$sequence;
        $this->output->persist("[opened resource #{$this->id}]");
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->output->persist("[cleanup: closed resource #{$this->id}]");
    }
}
