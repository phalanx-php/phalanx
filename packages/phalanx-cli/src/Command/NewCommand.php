<?php

declare(strict_types=1);

namespace Phalanx\Cli\Command;

use Phalanx\Cli\Scaffold\ProjectGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'new', description: 'Create a new Phalanx project')]
final class NewCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Project name (used as directory name)');
        $this->addOption('dir', 'd', InputOption::VALUE_REQUIRED, 'Parent directory');
        $this->addOption('no-install', null, InputOption::VALUE_NONE, 'Skip composer install');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');

        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9-]*$/', $name)) {
            $output->writeln('<error>Project name must start with a letter and contain only letters, numbers, and hyphens.</error>');
            return Command::FAILURE;
        }

        $parentDir = $input->getOption('dir') ?? getcwd();

        if ($parentDir === false) {
            $output->writeln('<error>Unable to determine current working directory.</error>');
            return Command::FAILURE;
        }

        $directory = rtrim($parentDir, '/') . '/' . $name;

        if (is_dir($directory)) {
            $entries = scandir($directory);

            if ($entries !== false && count($entries) > 2) {
                $output->writeln("<error>Directory {$directory} already exists and is not empty.</error>");
                return Command::FAILURE;
            }
        }

        $output->writeln('');
        $output->writeln("<info>Creating Phalanx project: {$name}</info>");
        $output->writeln('');

        (new ProjectGenerator())($name, $directory, $output);

        if (!$input->getOption('no-install')) {
            $output->writeln('');
            $output->writeln('Running composer install...');

            $process = new Process(['composer', 'install', '--no-interaction'], $directory);
            $process->setTimeout(120);

            $exitCode = $process->run(static function (string $type, string $buffer) use ($output): void {
                $output->write($buffer);
            });

            if ($exitCode !== 0) {
                $output->writeln('');
                $output->writeln('<comment>composer install failed. Run it manually in the project directory.</comment>');
            }
        }

        $output->writeln('');
        $output->writeln('<info>Project created.</info>');
        $output->writeln('');
        $output->writeln('Next steps:');
        $output->writeln("  cd {$name}");
        $output->writeln('  php public/index.php');
        $output->writeln('');

        return Command::SUCCESS;
    }
}
