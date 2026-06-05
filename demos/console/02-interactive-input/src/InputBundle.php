<?php

declare(strict_types=1);

namespace Phalanx\Demos\Console\InteractiveInput;

use Phalanx\Console\Input\KeyReader;
use Phalanx\Console\Input\RawInput;
use Phalanx\Console\Output\StreamOutput;
use Phalanx\Console\Style\Theme;
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
        // eager() is required: KeyReader is an interface and cannot be resolved
        // via the default lazy newLazyProxy path, which requires a concrete class.
        $services->scoped(KeyReader::class)->eager()->factory(static fn(): KeyReader => $reader);
    }
}
