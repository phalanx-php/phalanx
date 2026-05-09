<?php

declare(strict_types=1);

namespace Phalanx\Demos\Archon\InteractiveInput;

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
class InputBundle extends ServiceBundle
{
    public function __construct(
        private StreamOutput $output,
        private Theme $theme,
        private RawInput $reader,
    ) {
    }

    public function services(Services $services, AppContext $context): void
    {
        $output = $this->output;
        $theme = $this->theme;
        $reader = $this->reader;
        $services->singleton(StreamOutput::class)->factory(static fn(): StreamOutput => $output);
        $services->singleton(Theme::class)->factory(static fn(): Theme => $theme);
        $services->scoped(KeyReader::class)->factory(static fn(): RawInput => $reader);
    }
}
