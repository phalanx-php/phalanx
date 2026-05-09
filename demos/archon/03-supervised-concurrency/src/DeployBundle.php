<?php

declare(strict_types=1);

namespace Phalanx\Demos\Archon\SupervisedConcurrency;

use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Output\TerminalEnvironment;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

/**
 * Registers the output stream and theme for the concurrent deploy demo.
 * Under a non-TTY the stream is a php://temp capture buffer; under a real
 * terminal STDOUT is passed directly so the live spinner renders inline.
 */
class DeployBundle extends ServiceBundle
{
    public function __construct(
        /** @var resource */
        private mixed $stream,
        private ?TerminalEnvironment $terminal,
        private Theme $theme,
    ) {
    }

    public function services(Services $services, AppContext $context): void
    {
        $stream   = $this->stream;
        $theme    = $this->theme;
        $terminal = $this->terminal;

        $services->singleton(StreamOutput::class)
            ->factory(static fn(): StreamOutput => new StreamOutput($stream, $terminal));

        $services->singleton(Theme::class)
            ->factory(static fn(): Theme => $theme);
    }
}
