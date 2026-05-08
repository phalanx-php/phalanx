<?php

declare(strict_types=1);

namespace Phalanx\Archon\Console\Style;

use Phalanx\Archon\Console\Input\KeyReader;
use Phalanx\Archon\Console\Input\RawInput;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Output\TerminalEnvironment;
use Phalanx\Console\Input\ConsoleInput;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

/**
 * Registers the console foundation services.
 *
 * - Theme: process-wide singleton; override the binding in your application
 *   bundle to apply a custom color scheme without touching widget code.
 * - StreamOutput: process-wide singleton bound to the context-derived
 *   TerminalEnvironment.
 * - KeyReader: scoped (one per command scope) so the per-prompt key queue
 *   and KeyParser state survive across sequential prompts in a single
 *   command without leaking partial multi-byte sequences across scopes.
 */
final class ConsoleServiceBundle extends ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $services->singleton(Theme::class)
            ->factory(static fn() => Theme::default());

        $services->singleton(StreamOutput::class)
            ->factory(static fn() => new StreamOutput(terminal: TerminalEnvironment::fromContext($context)));

        $services->scoped(KeyReader::class)
            ->needs(ConsoleInput::class)
            ->factory(static fn(ConsoleInput $input): KeyReader => new RawInput($input));
    }
}
