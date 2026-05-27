<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit\Command;

use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Command\DescribesCommand;
use Phalanx\Archon\Tests\Fixtures\Commands\NoopCommand;
use Phalanx\Scope\Scope;
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
        self::assertSame('', $commands['noop']->config->description);
    }

    #[Test]
    public function tuple_form_short_circuits_interface_check(): void
    {
        $override = new CommandConfig(description: 'Override from Olympus');

        $group = CommandGroup::of([
            'march' => [HopliteCommand::class, $override],
        ]);

        $commands = $group->commands();

        self::assertArrayHasKey('march', $commands);
        self::assertSame('Override from Olympus', $commands['march']->config->description);
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
