<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Unit\Command;

use Phalanx\Console\Command\CommandConfig;
use Phalanx\Console\Command\CommandGroup;
use Phalanx\Console\Command\HelpGenerator;
use Phalanx\Console\Tests\Fixtures\Commands\NoopCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NestedHelpGeneratorTest extends TestCase
{
    #[Test]
    public function group_help_lists_commands(): void
    {
        $group = CommandGroup::of([
            'scan' => [NoopCommand::class, new CommandConfig(description: 'Scan a subnet')],
            'probe' => [NoopCommand::class, new CommandConfig(description: 'Probe a host')],
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
            'serve' => [NoopCommand::class, new CommandConfig(description: 'Start server')],
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
            'scan' => [NoopCommand::class, new CommandConfig(description: 'Scan')],
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
