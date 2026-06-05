<?php

declare(strict_types=1);

namespace Phalanx\Console\Testing;

use Phalanx\Boot\AppContext;
use Phalanx\Console\Input\ConsoleInput;
use Phalanx\Console\Input\KeyReader;
use Phalanx\Console\Input\RawInput;
use Phalanx\Console\Output\StreamOutput;
use Phalanx\Console\Style\Theme;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

/**
 * Internal capture-bundle used by Lens.
 *
 * Pre-empts the production Bundle by registering captured
 * StreamOutput, Theme, and a /dev/null-backed KeyReader. Lens
 * constructs this once per run() and discards it on shutdown.
 *
 * Not for userland direct use — userland reaches through Lens.
 */
class CaptureBundle extends ServiceBundle
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
