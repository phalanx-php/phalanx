<?php

declare(strict_types=1);

namespace Phalanx\Demos\Archon\BasicCommands;

use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

/**
 * Registers a caller-supplied StreamOutput so the demo can capture command
 * output into a php://temp stream for assertion.
 */
class OutputBundle extends ServiceBundle
{
    public function __construct(private StreamOutput $output)
    {
    }

    public function services(Services $services, AppContext $context): void
    {
        $output = $this->output;
        $services->singleton(StreamOutput::class)->factory(static fn(): StreamOutput => $output);
    }
}
