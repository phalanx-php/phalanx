<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Integration\Application;

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Command\CommandScope;
use Phalanx\Archon\Runtime\Identity\ArchonResourceSid;
use Phalanx\Runtime\Memory\ManagedResourceState;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Scopeable;
use Phalanx\Task\Task;
use Phalanx\Testing\Assert as PhalanxAssert;
use Phalanx\Testing\PhalanxTestCase;

final class ArchonApplicationScopeTest extends PhalanxTestCase
{
    public function testCommandRunsInsideAegisArchonScope(): void
    {
        $app = Archon::starting()
            ->commands(CommandGroup::of([
                'probe' => ArchonProbeCommand::class,
            ]))
            ->build();

        $this->scope->run(static function (ExecutionScope $_scope) use ($app): void {
            self::assertSame(0, $app->dispatch(['probe']));
        });

        self::assertTrue(ArchonProbeCommand::$receivedCommandScope);
        self::assertSame('probe', ArchonProbeCommand::$commandName);
        self::assertNotSame('', ArchonProbeCommand::$commandResourceId);
        self::assertSame('task:probe', ArchonProbeCommand::$taskResult);
        self::assertSame(ManagedResourceState::Closed, $app->host()->runtime()->memory->resources->all(
            ArchonResourceSid::Command,
        )[0]->state);
        PhalanxAssert::assertNoLiveTasks($app->host()->supervisor());
        $app->shutdown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        ArchonProbeCommand::$receivedCommandScope = false;
        ArchonProbeCommand::$commandName = null;
        ArchonProbeCommand::$commandResourceId = null;
        ArchonProbeCommand::$taskResult = null;
    }
}

final class ArchonProbeCommand implements Scopeable
{
    public static bool $receivedCommandScope = false;

    public static ?string $commandName = null;

    public static ?string $commandResourceId = null;

    public static ?string $taskResult = null;

    public function __invoke(CommandScope $scope): int
    {
        self::$receivedCommandScope = true;
        self::$commandName = $scope->commandName;
        self::$commandResourceId = $scope->commandResourceId;
        $commandName = $scope->commandName;
        self::$taskResult = $scope->execute(Task::named(
            'archon.proof',
            static fn(): string => "task:$commandName",
        ));

        return 0;
    }
}
