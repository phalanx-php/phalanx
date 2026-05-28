<?php

declare(strict_types=1);

namespace Phalanx\Cli\Command;

use Phalanx\Cli\Swoole\FlagSet;
use Phalanx\Cli\Swoole\PieRunner;
use Phalanx\Cli\Swoole\Platform;
use Phalanx\Cli\Swoole\PlatformDetector;
use Phalanx\Cli\Swoole\SwooleFlag;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(name: 'swoole:install', description: 'Install Swoole via PIE with guided flag selection')]
final class SwooleInstallCommand extends Command
{
    protected function configure(): void
    {
        foreach (SwooleFlag::interactiveChoices() as $flag) {
            $this->addOption($flag->value, null, InputOption::VALUE_NONE, $flag->description());
        }

        foreach (SwooleFlag::cases() as $flag) {
            if ($flag->needsValue()) {
                $this->addOption($flag->value, null, InputOption::VALUE_REQUIRED, $flag->description());
            }
        }

        $this->addOption('defaults', null, InputOption::VALUE_NONE, 'Accept default flags without prompting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('');

        if (extension_loaded('swoole')) {
            $version = phpversion('swoole');
            $output->writeln("<info>Swoole v{$version} is already installed.</info>");
            return Command::SUCCESS;
        }

        if (extension_loaded('openswoole')) {
            $output->writeln('<error>OpenSwoole is loaded, but Phalanx requires ext-swoole.</error>');
            $output->writeln('Disable OpenSwoole in your active php.ini before installing Swoole.');
            return Command::FAILURE;
        }

        $pie = new PieRunner();

        if (!$pie->isInstalled()) {
            $output->writeln('<error>PIE is not installed.</error>');
            $output->writeln('');
            $output->writeln('Install PIE first:');
            $output->writeln('  composer global require php/pie');
            $output->writeln('  Or download from: https://github.com/php/pie/releases');
            return Command::FAILURE;
        }

        $output->writeln("<info>PIE v{$pie->version()} found.</info>");
        $output->writeln('');

        $flagSet = $this->resolveFlags($input, $output);

        $platform = (new PlatformDetector())();
        $this->showSystemDependencies($output, $flagSet, $platform);

        if (!$input->getOption('defaults') && $input->isInteractive()) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $confirm = new ConfirmationQuestion('Proceed with installation? [Y/n] ', true);

            if (!$helper->ask($input, $output, $confirm)) {
                $output->writeln('Installation cancelled.');
                return Command::SUCCESS;
            }
        }

        $output->writeln('');
        $output->writeln('Installing Swoole...');
        $output->writeln('');

        $exitCode = $pie->install($flagSet, $output);

        if ($exitCode !== 0) {
            $output->writeln('');
            $output->writeln('<error>PIE installation failed (exit code: ' . $exitCode . ').</error>');
            return Command::FAILURE;
        }

        $output->writeln('');

        if (PieRunner::verifyExtensionLoaded()) {
            $output->writeln('<info>Swoole installed and loaded successfully.</info>');
            $output->writeln('Run `phalanx doctor` to verify your environment.');
            return Command::SUCCESS;
        }

        $output->writeln('<comment>Swoole was installed but may not be loaded in your php.ini.</comment>');
        $output->writeln('');
        $output->writeln('Check your active php.ini:');
        $output->writeln('  php --ini');
        $output->writeln('');
        $output->writeln('Ensure this line is present:');
        $output->writeln('  extension=swoole');

        return Command::SUCCESS;
    }

    /** @return list<array{SwooleFlag, ?string}> */
    private static function collectExplicitFlags(InputInterface $input): array
    {
        $flags = [];

        foreach (SwooleFlag::cases() as $flag) {
            $value = $input->getOption($flag->value);

            if ($value === false || $value === null) {
                continue;
            }

            $flags[] = [$flag, is_string($value) ? $value : null];
        }

        return $flags;
    }

    /**
     * @param list<array{SwooleFlag, ?string}> $pairs
     * @return array<string, string>
     */
    private static function collectFlagValues(array $pairs): array
    {
        $values = [];

        foreach ($pairs as [$flag, $value]) {
            if ($value !== null) {
                $values[$flag->value] = $value;
            }
        }

        return $values;
    }

    private static function detectOpensslDir(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $helper,
    ): ?string {
        foreach (self::candidateOpensslDirs() as $path) {
            if (is_dir($path)) {
                $output->writeln('');
                $output->writeln("<info>Homebrew OpenSSL detected at {$path}</info>");

                $confirm = new ConfirmationQuestion(
                    "Use --with-openssl-dir={$path}? [Y/n] ",
                    true,
                );

                if ($helper->ask($input, $output, $confirm)) {
                    return $path;
                }

                continue;
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function candidateOpensslDirs(): array
    {
        $paths = [];

        foreach (['openssl@3', 'openssl'] as $formula) {
            $path = self::homebrewPrefix($formula);

            if ($path !== null) {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    private static function homebrewPrefix(string $formula): ?string
    {
        exec('brew --prefix ' . escapeshellarg($formula) . ' 2>/dev/null', $output, $exitCode);

        if ($exitCode !== 0 || $output === []) {
            return null;
        }

        $path = trim($output[0]);

        return $path !== '' ? $path : null;
    }

    private function resolveFlags(InputInterface $input, OutputInterface $output): FlagSet
    {
        if ($input->getOption('defaults')) {
            $flagSet = FlagSet::defaults();
            $output->writeln('Using default flags: ' . implode(', ', array_map(
                static fn (SwooleFlag $f): string => $f->value,
                $flagSet->flags,
            )));
            return $flagSet;
        }

        $explicitFlags = self::collectExplicitFlags($input);

        if ($explicitFlags !== []) {
            return new FlagSet(
                array_map(static fn (array $pair): SwooleFlag => $pair[0], $explicitFlags),
                self::collectFlagValues($explicitFlags),
            );
        }

        if (!$input->isInteractive()) {
            return FlagSet::defaults();
        }

        return $this->promptForFlags($input, $output);
    }

    private function promptForFlags(InputInterface $input, OutputInterface $output): FlagSet
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $choices = SwooleFlag::interactiveChoices();

        $labels = [];
        $defaults = [];

        foreach ($choices as $i => $flag) {
            $labels[] = "{$flag->value} - {$flag->description()}";

            if ($flag->defaultEnabled()) {
                $defaults[] = (string) $i;
            }
        }

        $question = new ChoiceQuestion(
            'Select Swoole features to enable (comma-separated, Enter for defaults):',
            $labels,
            implode(',', $defaults),
        );
        $question->setMultiselect(true);

        /** @var list<string> $selected */
        $selected = $helper->ask($input, $output, $question);

        $selectedFlags = [];

        foreach ($selected as $label) {
            $value = explode(' - ', $label, 2)[0];
            $flag = SwooleFlag::tryFrom($value);

            if ($flag !== null) {
                $selectedFlags[] = $flag;
            }
        }

        if ($selectedFlags === []) {
            return FlagSet::defaults();
        }

        $values = [];

        if (
            array_any($selectedFlags, static fn (SwooleFlag $f): bool => $f === SwooleFlag::EnableOpenssl)
            && PHP_OS_FAMILY === 'Darwin'
        ) {
            $result = self::detectOpensslDir($input, $output, $helper);

            if ($result !== null) {
                $selectedFlags[] = SwooleFlag::WithOpensslDir;
                $values[SwooleFlag::WithOpensslDir->value] = $result;
            }
        }

        return new FlagSet($selectedFlags, $values);
    }

    private function showSystemDependencies(OutputInterface $output, FlagSet $flagSet, Platform $platform): void
    {
        if ($platform === Platform::Unknown) {
            return;
        }

        $deps = $flagSet->systemDependenciesFor($platform);

        if ($deps === []) {
            return;
        }

        $output->writeln('<comment>System dependencies for your platform:</comment>');

        foreach ($deps as $hint) {
            $output->writeln("  {$hint->packageName}: {$hint->installCommand}");
        }

        $output->writeln('');
    }
}
