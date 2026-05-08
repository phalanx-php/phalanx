<?php

declare(strict_types=1);

namespace Phalanx\Archon\Examples\InteractiveInput;

use Phalanx\Archon\Console\Input\KeyReader;
use Phalanx\Archon\Console\Input\RawInput;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

/**
 * Registers caller-supplied output, theme, and a /dev/null-backed KeyReader
 * so interactive prompts short-circuit to their defaults and the demo
 * produces deterministic output for assertion.
 */
final class InputBundle extends ServiceBundle
{
    public function __construct(
        private readonly StreamOutput $output,
        private readonly Theme $theme,
        private readonly RawInput $reader,
    ) {
    }

    public function services(Services $services, AppContext $context): void
    {
        $services->singleton(StreamOutput::class)->factory(fn() => $this->output);
        $services->singleton(Theme::class)->factory(fn() => $this->theme);
        $services->scoped(KeyReader::class)->factory(fn() => $this->reader);
    }
}
