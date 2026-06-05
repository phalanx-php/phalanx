<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Integration\Application;

use Phalanx\Console\Application\Console;
use Phalanx\Console\Command\CommandGroup;
use Phalanx\Console\Command\CommandContext;
use Phalanx\Console\Runtime\Identity\ConsoleResourceSid;
use Phalanx\Runtime\Memory\ManagedResourceState;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Scopeable;
use Phalanx\Task\Task;
use Phalanx\Testing\Assert as PhalanxAssert;
use Phalanx\Testing\PhalanxTestCase;

final class ConsoleApplicationScopeTest extends PhalanxTestCase
{
    public function testCommandRunsInsideRuntimeConsoleScope(): void
    {
        $app = Console::starting()
            ->commands(CommandGroup::of([
                'probe' => ConsoleProbeCommand::class,
            ]))
            ->build();

        $this->scope->run(static function (ExecutionScope $_scope) use ($app): void {
            self::assertSame(0, $app->dispatch(['probe']));
        });

        self::assertTrue(ConsoleProbeCommand::$receivedCommandContext);
        self::assertSame('probe', ConsoleProbeCommand::$commandName);
        self::assertNotSame('', ConsoleProbeCommand::$commandResourceId);
        self::assertSame('task:probe', ConsoleProbeCommand::$taskResult);
        self::assertSame(ManagedResourceState::Closed, $app->host()->runtime()->memory->resources->all(
            ConsoleResourceSid::Command,
        )[0]->state);
        PhalanxAssert::assertNoLiveTasks($app->host()->supervisor());
        $app->shutdown();
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
