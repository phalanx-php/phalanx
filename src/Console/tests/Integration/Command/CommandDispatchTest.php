<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Integration\Command;

use Phalanx\Application;
use Phalanx\Console\Command\CommandConfig;
use Phalanx\Console\Command\CommandGroup;
use Phalanx\Console\Tests\Support\TestCase;
use Phalanx\Console\Tests\Fixtures\Commands\FailingCommand;
use Phalanx\Console\Tests\Fixtures\Commands\NoopCommand;
use Phalanx\Scope\ExecutionScope;
use PHPUnit\Framework\Attributes\Test;

final class CommandDispatchTest extends TestCase
{
    private Application $app;

    #[Test]
    public function dispatches_command_by_direct_key(): void
    {
        $group = CommandGroup::of([
            'migrate' => NoopCommand::class,
            'seed' => FailingCommand::class,
        ]);

        $result = $this->app->scoped(static function (ExecutionScope $scope) use ($group): int {
            return $group->dispatch($scope, 'migrate', [], 'console-command-test');
        });

        self::assertSame(0, $result);
    }

    #[Test]
    public function throws_when_command_not_found(): void
    {
        $group = CommandGroup::of([
            'migrate' => NoopCommand::class,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Command not found: unknown');

        $this->app->scoped(static function (ExecutionScope $scope) use ($group): int {
            return $group->dispatch($scope, 'unknown', [], 'console-command-test');
        });
    }

    #[Test]
    public function command_group_keys_and_merge(): void
    {
        $group1 = CommandGroup::of([
            'migrate' => [NoopCommand::class, new CommandConfig(description: 'Run migrations')],
        ]);

        $group2 = CommandGroup::of([
            'seed' => [NoopCommand::class, new CommandConfig(description: 'Seed database')],
        ]);

        $merged = $group1->merge($group2);

        self::assertCount(2, $merged->keys());
        self::assertContains('migrate', $merged->keys());
        self::assertContains('seed', $merged->keys());
    }

    #[Test]
    public function command_config_preserved(): void
    {
        $group = CommandGroup::of([
            'migrate' => [NoopCommand::class, new CommandConfig(description: 'Run migrations')],
        ]);

        $handler = $group->handlers()->get('migrate');

        self::assertNotNull($handler);
        self::assertInstanceOf(CommandConfig::class, $handler->config);
        self::assertSame('Run migrations', $handler->config->description);
    }

    protected function setUp(): void
    {
        $this->app = $this->application();
    }
}
