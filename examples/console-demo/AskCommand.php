<?php

declare(strict_types=1);

namespace Phalanx\Archon\Demo;

use Phalanx\Archon\Command\CommandScope;
use Phalanx\Archon\Console\Input\ConfirmInput;
use Phalanx\Archon\Console\Input\KeyReader;
use Phalanx\Archon\Console\Input\TextInput;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Task\Scopeable;

/**
 * End-to-end prompt example. Resolves Theme/StreamOutput/KeyReader from the
 * command scope, drives a TextInput then a ConfirmInput, and persists the
 * answers. Skips interactively when stdin is not a TTY (CI, redirected
 * input) so the example stays runnable in non-interactive environments —
 * the prompt() implementation itself returns the configured default.
 */
final class AskCommand implements Scopeable
{
    public function __invoke(CommandScope $scope): int
    {
        $theme  = $scope->service(Theme::class);
        $output = $scope->service(StreamOutput::class);
        $reader = $scope->service(KeyReader::class);

        $name = (new TextInput(
            theme:   $theme,
            label:   'What is your name?',
            default: 'operator',
        ))->prompt($scope, $output, $reader);

        $confirmed = (new ConfirmInput(
            theme:   $theme,
            label:   "Greet {$name}?",
            default: true,
        ))->prompt($scope, $output, $reader);

        $output->persist(
            $confirmed ? "Hello, {$name}." : 'Skipped.',
        );

        return 0;
    }
}
