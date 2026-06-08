<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Unit\Command;

use Phalanx\Console\Command\Arg;
use Phalanx\Console\Command\CommandConfig;
use Phalanx\Console\Command\CommandGroup;
use Phalanx\Console\Command\DescribesCommand;
use Phalanx\Console\Command\Opt;
use Phalanx\Console\Tests\Fixtures\Commands\NoopCommand;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DescribesCommandTest extends TestCase
{
    #[Test]
    public function bare_class_string_uses_self_described_config(): void
    {
        $group = CommandGroup::of([
            'march' => HopliteCommand::class,
        ]);

        $commands = $group->commands();

        self::assertArrayHasKey('march', $commands);
        self::assertInstanceOf(CommandConfig::class, $commands['march']->config);
        self::assertSame('Form the phalanx and advance', $commands['march']->config->description);
    }

    #[Test]
    public function bare_class_string_without_interface_gets_default_config(): void
    {
        $group = CommandGroup::of([
            'noop' => NoopCommand::class,
        ]);

        $commands = $group->commands();

        self::assertArrayHasKey('noop', $commands);
        self::assertInstanceOf(CommandConfig::class, $commands['noop']->config);
        self::assertSame('', $commands['noop']->config->description);
    }

    #[Test]
    public function self_described_config_preserves_arguments_and_options(): void
    {
        $group = CommandGroup::of([
            'deploy' => SpartanDeployCommand::class,
        ]);

        $commands = $group->commands();

        self::assertArrayHasKey('deploy', $commands);

        $config = $commands['deploy']->config;
        assert($config instanceof CommandConfig);

        self::assertSame('Deploy forces to the front line', $config->description);
        self::assertCount(1, $config->arguments);
        self::assertSame('target', $config->arguments[0]->name);
        self::assertCount(1, $config->options);
        self::assertSame('force', $config->options[0]->name);
    }

    #[Test]
    public function executable_with_describes_command_detected(): void
    {
        $group = CommandGroup::of([
            'siege' => SiegeCommand::class,
        ]);

        $commands = $group->commands();

        self::assertArrayHasKey('siege', $commands);
        self::assertInstanceOf(CommandConfig::class, $commands['siege']->config);
        self::assertSame('Lay siege to enemy fortifications', $commands['siege']->config->description);
    }

}

final class HopliteCommand implements Scopeable, DescribesCommand
{
    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(description: 'Form the phalanx and advance');
    }

    public function __invoke(Scope $scope): int
    {
        return 0;
    }
}

final class SpartanDeployCommand implements Scopeable, DescribesCommand
{
    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(
            description: 'Deploy forces to the front line',
            arguments: [Arg::required('target', 'Target location')],
            options: [Opt::flag('force', 'f', 'Skip confirmation')],
        );
    }

    public function __invoke(Scope $scope): int
    {
        return 0;
    }
}

final class SiegeCommand implements Executable, DescribesCommand
{
    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(description: 'Lay siege to enemy fortifications');
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        return 0;
    }
}
