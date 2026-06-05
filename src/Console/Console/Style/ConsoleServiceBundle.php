<?php

declare(strict_types=1);

namespace Phalanx\Console\Console\Style;

use Phalanx\Boot\AppContext;
use Phalanx\Console\Console\Input\KeyReader;
use Phalanx\Console\Console\Input\RawInput;
use Phalanx\Console\Console\Output\StreamOutput;
use Phalanx\Console\Console\Output\TerminalEnvironment;
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
    public function services(Services $services, AppContext $context): void
    {
        if (!$services->has(Theme::class)) {
            $services->singleton(Theme::class)
                ->factory(static fn() => Theme::default());
        }

        if (!$services->has(StreamOutput::class)) {
            $services->singleton(StreamOutput::class)
                ->factory(static fn() => new StreamOutput(terminal: TerminalEnvironment::fromContext($context)));
        }

        if (!$services->has(KeyReader::class)) {
            // KeyReader is an interface, so the factory must construct it eagerly.
            $services->scoped(KeyReader::class)
                ->eager()
                ->needs(ConsoleInput::class)
                ->factory(static fn(ConsoleInput $input): KeyReader => new RawInput($input));
        }
    }
}
