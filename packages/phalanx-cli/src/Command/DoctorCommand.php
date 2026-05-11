<?php

declare(strict_types=1);

namespace Phalanx\Cli\Command;

use Phalanx\Cli\Doctor\CheckStatus;
use Phalanx\Cli\Doctor\EnvironmentChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'doctor', description: 'Check environment readiness for Phalanx')]
final class DoctorCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('');
        $output->writeln('<info>Phalanx Environment Check</info>');
        $output->writeln(str_repeat('-', 40));
        $output->writeln('');

        $checks = (new EnvironmentChecker())();
        $hasFailures = false;

        foreach ($checks as $check) {
            $icon = match ($check->status) {
                CheckStatus::Pass => '<info>✓</info>',
                CheckStatus::Warn => '<comment>!</comment>',
                CheckStatus::Fail => '<error>✗</error>',
            };

            $message = match ($check->status) {
                CheckStatus::Pass => "<info>{$check->message}</info>",
                CheckStatus::Warn => "<comment>{$check->message}</comment>",
                CheckStatus::Fail => "<error>{$check->message}</error>",
            };

            $output->writeln("  {$icon} {$check->name}: {$message}");

            if ($check->remediation !== null) {
                foreach (explode("\n", $check->remediation) as $line) {
                    $output->writeln("      {$line}");
                }
            }

            if ($check->isFail()) {
                $hasFailures = true;
            }
        }

        $output->writeln('');

        if ($hasFailures) {
            $output->writeln('<error>Some checks failed. See remediation steps above.</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Environment is ready for Phalanx.</info>');
        return Command::SUCCESS;
    }
}
