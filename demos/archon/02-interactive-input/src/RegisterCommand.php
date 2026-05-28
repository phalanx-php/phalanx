<?php

declare(strict_types=1);

namespace Phalanx\Demos\Archon\InteractiveInput;

use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\DescribesCommand;
use Phalanx\Archon\Console\Input\ConfirmInput;
use Phalanx\Archon\Console\Input\KeyReader;
use Phalanx\Archon\Console\Input\SelectInput;
use Phalanx\Archon\Console\Input\TextInput;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Archon\Console\Widget\Form;
use Phalanx\Archon\Console\Widget\FormRevertedException;
use Phalanx\Task\Scopeable;

/**
 * Drives a multi-field Form with three prompt types and a validate closure.
 * Under a TTY the user fills the form interactively; under a non-TTY stream
 * each prompt short-circuits to its configured default and the command still
 * produces deterministic output that demo.php asserts on.
 */
final class RegisterCommand implements Scopeable, DescribesCommand
{
    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(description: 'Register a demo account through interactive prompts.');
    }

    public function __invoke(CommandContext $ctx): int
    {
        $theme  = $ctx->service(Theme::class);
        $output = $ctx->service(StreamOutput::class);
        $reader = $ctx->service(KeyReader::class);

        $form = (new Form())
            ->text('email', static fn() => new TextInput(
                theme:    $theme,
                label:    'Email',
                default:  'demo@phalanx.local',
                validate: static fn(mixed $value): ?string
                    => is_string($value) && preg_match('/^[^@\s]+@[^@\s]+$/', $value) === 1
                        ? null
                        : 'expected name@host',
            ))
            ->confirm('terms', static fn() => new ConfirmInput(
                theme:   $theme,
                label:   'Accept terms?',
                default: true,
            ))
            ->select('plan', static fn() => new SelectInput(
                theme:   $theme,
                label:   'Plan',
                options: ['free' => 'Free', 'pro' => 'Pro', 'team' => 'Team'],
            ));

        try {
            $values = $form->submit($ctx, $output, $reader);
        } catch (FormRevertedException) {
            $output->persist('Cancelled.');
            return 0;
        }

        $output->persist(sprintf(
            'Registered: %s on %s (terms=%s)',
            (string) $values['email'],
            (string) $values['plan'],
            $values['terms'] ? 'yes' : 'no',
        ));

        return 0;
    }
}
