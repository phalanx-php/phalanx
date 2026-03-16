<?php

declare(strict_types=1);

namespace Convoy\Tests\Console\Integration;

use Convoy\Application;
use Convoy\Console\Command;
use Convoy\Console\CommandConfig;
use Convoy\Console\CommandGroup;
use Convoy\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CommandDispatchTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = Application::starting()->compile();
    }

    protected function tearDown(): void
    {
        $this->app->shutdown();
    }

    #[Test]
    public function dispatches_command_by_command_attribute(): void
    {
        $group = CommandGroup::of([
            'migrate' => new Command(fn: static fn() => 0, config: new CommandConfig()),
            'seed' => new Command(fn: static fn() => 1, config: new CommandConfig()),
        ]);

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('command', 'migrate');

        $result = $scope->execute($group);

        $this->assertSame(0, $result);
    }

    #[Test]
    public function throws_when_command_not_found(): void
    {
        $group = CommandGroup::of([
            'migrate' => new Command(fn: static fn() => 0, config: new CommandConfig()),
        ]);

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('command', 'unknown');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Command not found: unknown');

        $scope->execute($group);
    }

    #[Test]
    public function command_group_keys_and_merge(): void
    {
        $group1 = CommandGroup::of([
            'migrate' => new Command(fn: static fn() => 0, config: new CommandConfig(description: 'Run migrations')),
        ]);

        $group2 = CommandGroup::of([
            'seed' => new Command(fn: static fn() => 0, config: new CommandConfig(description: 'Seed database')),
        ]);

        $merged = $group1->merge($group2);

        $this->assertCount(2, $merged->keys());
        $this->assertContains('migrate', $merged->keys());
        $this->assertContains('seed', $merged->keys());
    }

    #[Test]
    public function command_config_preserved(): void
    {
        $group = CommandGroup::of([
            'migrate' => new Command(fn: static fn() => 0, config: new CommandConfig(description: 'Run migrations')),
        ]);

        $handler = $group->handlers()->get('migrate');

        $this->assertInstanceOf(CommandConfig::class, $handler->config);
        $this->assertSame('Run migrations', $handler->config->description);
    }

    #[Test]
    public function fluent_command_adds_handler(): void
    {
        $group = CommandGroup::create()
            ->command('migrate', Task::of(static fn() => 0), 'Run migrations');

        $handler = $group->handlers()->get('migrate');

        $this->assertNotNull($handler);
        $this->assertInstanceOf(CommandConfig::class, $handler->config);
    }
}
