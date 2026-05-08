<?php

declare(strict_types=1);

namespace Phalanx\Archon\Testing;

use Phalanx\Archon\Console\Input\KeyReader;
use Phalanx\Archon\Console\Input\RawInput;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Boot\AppContext;
use Phalanx\Console\Input\ConsoleInput;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

/**
 * Internal capture-bundle used by ConsoleLens.
 *
 * Pre-empts the production ConsoleServiceBundle by registering captured
 * StreamOutput, Theme, and a /dev/null-backed KeyReader. ConsoleLens
 * constructs this once per run() and discards it on shutdown.
 *
 * Not for userland direct use — userland reaches through ConsoleLens.
 */
final class ConsoleCaptureBundle extends ServiceBundle
{
    /** @param resource $nullInput */
    public function __construct(
        private StreamOutput $output,
        private mixed $nullInput,
    ) {
    }

    public function services(Services $services, AppContext $context): void
    {
        $output = $this->output;
        $nullInput = $this->nullInput;

        $services->singleton(StreamOutput::class)
            ->factory(static fn(): StreamOutput => $output);

        $services->singleton(Theme::class)
            ->factory(static fn(): Theme => Theme::default());

        $services->scoped(KeyReader::class)
            ->factory(static fn(): RawInput => new RawInput(new ConsoleInput($nullInput)));
    }
}
