<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Unit\Command;

use Phalanx\Console\Command\CommandConfig;
use Phalanx\Console\Command\CommandGroup;
use Phalanx\Console\Command\HelpGenerator;
use Phalanx\Console\Command\DescribesCommand;
use Phalanx\Console\Tests\Fixtures\Commands\FlatRanCommand;
use Phalanx\Console\Tests\Fixtures\Commands\NoopCommand;
use Phalanx\Scope\Scope;
use Phalanx\Task\Scopeable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NestedHelpGeneratorTest extends TestCase
{
    #[Test]
    public function group_help_lists_commands(): void
    {
        $group = CommandGroup::of([
            'scan' => ScanSubnetCommand::class,
            'probe' => ProbeHostCommand::class,
        ], description: 'Network operations');

        $help = HelpGenerator::forGroup('net', $group);

        self::assertStringContainsString('Network operations', $help);
        self::assertStringContainsString('net <command>', $help);
        self::assertStringContainsString('scan', $help);
        self::assertStringContainsString('Scan a subnet', $help);
        self::assertStringContainsString('probe', $help);
        self::assertStringContainsString('Probe a host', $help);
    }

    #[Test]
    public function top_level_help_separates_groups_and_commands(): void
    {
        $root = CommandGroup::of([
            'serve' => FlatRanCommand::class,
            'net' => CommandGroup::of([
                'scan' => NoopCommand::class,
            ], description: 'Network operations'),
        ], description: 'myapp');

        $help = HelpGenerator::forTopLevel($root);

        self::assertStringContainsString('Commands:', $help);
        self::assertStringContainsString('serve', $help);
        self::assertStringContainsString('Groups:', $help);
        self::assertStringContainsString('net', $help);
        self::assertStringContainsString('Network operations', $help);
    }

    #[Test]
    public function group_help_with_nested_subgroups(): void
    {
        $group = CommandGroup::of([
            'scan' => NoopCommand::class,
            'deep' => CommandGroup::of([
                'inner' => NoopCommand::class,
            ], description: 'Deeper group'),
        ], description: 'Outer');

        $help = HelpGenerator::forGroup('test', $group);

        self::assertStringContainsString('Commands:', $help);
        self::assertStringContainsString('Groups:', $help);
        self::assertStringContainsString('deep', $help);
        self::assertStringContainsString('Deeper group', $help);
    }
}

final class ScanSubnetCommand implements Scopeable, DescribesCommand
{
    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(description: 'Scan a subnet');
    }

    public function __invoke(Scope $scope): int
    {
        return 0;
    }
}

final class ProbeHostCommand implements Scopeable, DescribesCommand
{
    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(description: 'Probe a host');
    }

    public function __invoke(Scope $scope): int
    {
        return 0;
    }
}
