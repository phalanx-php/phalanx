<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Integration\Application;

use Phalanx\Console\Command\CommandGroup;
use Phalanx\Console\Command\CommandContext;
use Phalanx\Console\Testing\TestableBundle;
use Phalanx\Console\Tests\Support\TestCase;
use Phalanx\Task\Scopeable;
use Phalanx\Task\Task;

final class ApplicationScopeTest extends TestCase
{
    public function testCommandRunsInsideRuntimeConsoleScope(): void
    {
        $app = $this->testApp([], new TestableBundle());

        $app->console
            ->commands(CommandGroup::of([
                'probe' => ConsoleProbeCommand::class,
            ]))
            ->run(['probe'])
            ->assertSuccessful()
            ->assertCommandResourcesClosed()
            ->assertNoLiveCommandResources()
            ->assertNoLiveRuntimeScopes()
            ->assertNoLiveTasks();

        self::assertTrue(ConsoleProbeCommand::$receivedCommandContext);
        self::assertSame('probe', ConsoleProbeCommand::$commandName);
        self::assertNotSame('', ConsoleProbeCommand::$commandResourceId);
        self::assertSame('task:probe', ConsoleProbeCommand::$taskResult);
    }

    protected function setUp(): void
    {
        parent::setUp();

        ConsoleProbeCommand::$receivedCommandContext = false;
        ConsoleProbeCommand::$commandName = null;
        ConsoleProbeCommand::$commandResourceId = null;
        ConsoleProbeCommand::$taskResult = null;
    }
}

final class ConsoleProbeCommand implements Scopeable
{
    public static bool $receivedCommandContext = false;

    public static ?string $commandName = null;

    public static ?string $commandResourceId = null;

    public static ?string $taskResult = null;

    public function __invoke(CommandContext $ctx): int
    {
        self::$receivedCommandContext = true;
        self::$commandName = $ctx->commandName;
        self::$commandResourceId = $ctx->commandResourceId;
        $commandName = $ctx->commandName;
        self::$taskResult = $ctx->execute(Task::named(
            'console.proof',
            static fn(): string => "task:$commandName",
        ));

        return 0;
    }
}
