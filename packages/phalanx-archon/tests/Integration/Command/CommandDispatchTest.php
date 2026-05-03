<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Integration\Command;

use Phalanx\Application;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Tests\Fixtures\Commands\FailingCommand;
use Phalanx\Archon\Tests\Fixtures\Commands\NoopCommand;
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
            'migrate' => NoopCommand::class,
            'seed' => FailingCommand::class,
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
            'migrate' => NoopCommand::class,
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
            'migrate' => [NoopCommand::class, new CommandConfig(description: 'Run migrations')],
        ]);

        $group2 = CommandGroup::of([
            'seed' => [NoopCommand::class, new CommandConfig(description: 'Seed database')],
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
            'migrate' => [NoopCommand::class, new CommandConfig(description: 'Run migrations')],
        ]);

        $handler = $group->handlers()->get('migrate');

        $this->assertNotNull($handler);
        $this->assertInstanceOf(CommandConfig::class, $handler->config);
        $this->assertSame('Run migrations', $handler->config->description);
    }

}
